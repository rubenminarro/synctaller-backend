<?php

namespace Database\Seeders;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\Supplier;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

/**
 * Ajustes de inventario de ejemplo, de los últimos días: un conteo físico con
 * faltante y otro con sobrante, una pérdida y una devolución al proveedor.
 * Corre al final, después de todos los consumos de las OT, para que el kardex
 * (saldo acumulado por orden de carga) quede en orden cronológico.
 */
class InventoryAdjustmentSeeder extends Seeder
{
    public function run(InventoryService $inventoryService): void
    {
        $user = ExampleScenarios::user('deposito2@mail.com.py');
        $at = fn (int $hour) => ExampleScenarios::at(['days' => 1], 0, $hour);

        // Conteos físicos: el sistema registra la diferencia contra el stock actual.
        foreach ([['FIL-HAB-001', -1, 'Conteo físico mensual: faltante.'], ['ELE-LAM-001', 2, 'Conteo físico mensual: sobrante.']] as [$sku, $difference, $reason]) {
            $part = Part::where('sku', $sku)->firstOrFail();

            // Idempotente: cada movimiento de ejemplo se reconoce por su motivo.
            if ($this->exists($part, $reason)) {
                continue;
            }

            ExampleScenarios::travel($at(9), fn () => $inventoryService->adjust([
                'part_id' => $part->id,
                'counted_quantity' => bcadd($part->stock->quantity_on_hand, (string) $difference, 3),
                'unit_cost' => $part->cost_price,
                'reason' => $reason,
            ], $user));
        }

        $exits = [
            ['REF-REF-001', InventoryMovementType::LOSS, 0.5, 'Derrame al trasvasar el bidón.', null, null],
            ['FIL-AIR-001', InventoryMovementType::SUPPLIER_RETURN, 1, 'Devuelto por defecto de fábrica.', '80000001-1', 'NC 001-001-0000123'],
        ];

        foreach ($exits as [$sku, $type, $quantity, $reason, $supplierTaxId, $document]) {
            $part = Part::where('sku', $sku)->firstOrFail();

            if ($this->exists($part, $reason)) {
                continue;
            }

            ExampleScenarios::travel($at(15), fn () => $inventoryService->registerExit([
                'part_id' => $part->id,
                'type' => $type->value,
                'quantity' => $quantity,
                'reason' => $reason,
                'supplier_id' => $supplierTaxId ? Supplier::where('tax_id', $supplierTaxId)->value('id') : null,
                'document_number' => $document,
            ], $user));
        }
    }

    private function exists(Part $part, string $reason): bool
    {
        return InventoryMovement::where('part_id', $part->id)->where('reason', $reason)->exists();
    }
}

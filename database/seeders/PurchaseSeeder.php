<?php

namespace Database\Seeders;

use App\Enums\InvoiceCondition;
use App\Enums\PurchaseStatus;
use App\Models\Part;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseService;
use Illuminate\Database\Seeder;

/**
 * Compras a proveedores (costos con IVA incluido; 10%, 5% y exento por línea),
 * cargadas y recibidas por depósito: al contado y a crédito, recibidas (entra
 * el stock), una anulada (revierte el stock) y dos en borrador.
 *
 * Las que mueven stock son anteriores a los escenarios del taller y este seeder
 * corre antes que ellos: el kardex guarda el saldo acumulado y se lista por
 * orden de carga, así que los movimientos se cargan en orden cronológico. Los
 * borradores (sin stock) son recientes. Los pagos van en SupplierPaymentSeeder.
 */
class PurchaseSeeder extends Seeder
{
    /**
     * Código interno de referencia (va en las notas) => [días atrás, RUC del proveedor, condición, días de plazo,
     * estado final, ítems [SKU, cantidad, costo unitario con IVA, tasa]].
     */
    public const PURCHASES = [
        'P1' => [98, '80000001-1', 'cash', null, 'received', [['FIL-ACE-001', 10, 35000, 10], ['MOT-BUJ-001', 12, 25000, 10], ['FIL-AIR-001', 6, 45000, 10]]],
        'P2' => [97, '80000002-2', 'credit', 30, 'received', [['LUB-5W30-001', 40, 55000, 10], ['FRE-LIQ-001', 6, 30000, 5]]],
        'P3' => [96, '80000003-3', 'credit', 30, 'received', [['FRE-PAS-001', 4, 180000, 10], ['SUS-AMO-001', 4, 520000, 10]]],
        'P4' => [95, '80000004-4', 'credit', 45, 'received', [['ELE-BAT-001', 2, 650000, 10], ['ELE-LAM-001', 20, 15000, 0]]],
        'P5' => [94, '80000001-1', 'cash', null, 'cancelled', [['NEU-205-001', 4, 420000, 10]]],
        'P6' => [93, '80000001-1', 'credit', 120, 'received', [['FIL-HAB-001', 4, 40000, 10], ['MOT-COR-001', 1, 480000, 10]]],
        'P7' => [2, '80000003-3', 'credit', 30, 'draft', [['SUS-BUJ-001', 6, 90000, 10]]],
        // El kit de embrague que espera la OT en curso: pedido, todavía sin la factura del proveedor.
        'P8' => [1, '80000001-1', 'cash', null, 'draft', [['TRA-EMB-001', 2, 950000, 10]]],
    ];

    public function run(PurchaseService $purchaseService): void
    {
        $storekeepers = ['deposito1@mail.com.py', 'deposito2@mail.com.py'];
        $number = 0;

        foreach (self::PURCHASES as $key => [$days, $taxId, $condition, $term, $status, $items]) {
            $number++;

            // Idempotente: la referencia va en las notas de la compra.
            if (self::of($key)) {
                continue;
            }

            $user = ExampleScenarios::user($storekeepers[$number % 2]);
            $supplier = Supplier::where('tax_id', $taxId)->firstOrFail();
            $date = ExampleScenarios::at(['days' => $days], 0, 9);

            $purchase = ExampleScenarios::travel($date, function () use ($purchaseService, $user, $supplier, $condition, $term, $items, $key, $number, $date) {
                $purchase = $purchaseService->create([
                    'supplier_id' => $supplier->id,
                    'supplier_document_number' => sprintf('001-001-%07d', 4500 + $number),
                    'supplier_document_date' => $date->toDateString(),
                    'supplier_stamp_number' => (string) (87654300 + $number),
                    'condition' => $condition === 'credit' ? InvoiceCondition::CREDIT->value : InvoiceCondition::CASH->value,
                    'due_date' => $term ? $date->addDays($term)->toDateString() : null,
                    'notes' => "Compra de ejemplo {$key}.",
                ], $user);

                foreach ($items as [$sku, $quantity, $unitCost, $taxRate]) {
                    $purchase = $purchaseService->addItem($purchase, [
                        'part_id' => Part::where('sku', $sku)->firstOrFail()->id,
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'tax_rate' => $taxRate,
                    ]);
                }

                return $purchase;
            });

            if ($status === 'draft') {
                continue;
            }

            $purchase = ExampleScenarios::travel($date->setTime(11, 0), fn () => $purchaseService->receive($purchase, $user));

            if ($status === 'cancelled') {
                ExampleScenarios::travel($date->addDay()->setTime(10, 0), fn () => $purchaseService->cancel(
                    $purchase,
                    'El proveedor anuló la factura: la envió duplicada.',
                    $user
                ));
            }
        }
    }

    /**
     * Compra de ejemplo por su referencia (P1…P8).
     */
    public static function of(string $key): ?Purchase
    {
        return Purchase::where('notes', "Compra de ejemplo {$key}.")->first();
    }

    /**
     * Compras recibidas (con saldo a pagar), para SupplierPaymentSeeder.
     */
    public static function received(string $key): ?Purchase
    {
        $purchase = self::of($key);

        return $purchase?->status === PurchaseStatus::RECEIVED ? $purchase : null;
    }
}

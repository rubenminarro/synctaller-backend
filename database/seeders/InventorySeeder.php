<?php

namespace Database\Seeders;

use App\Enums\InventoryMovementType;
use App\Enums\PartStatus;
use App\Models\Part;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

/**
 * Stock inicial de los repuestos, cargado por el usuario de depósito. Quedan
 * bajo el mínimo el refrigerante y los discos de freno, y sin stock el kit de
 * embrague (para que reservarlo en una OT avise a depósito). El repuesto
 * descontinuado no admite entradas.
 */
class InventorySeeder extends Seeder
{
    public function run(InventoryService $inventoryService): void
    {
        $user = User::where('email', 'deposito1@mail.com.py')->first()
            ?? User::where('email', 'admin@mail.com.py')->first();

        if (!$user) {
            return;
        }

        $initialStock = [
            'FIL-ACE-001' => 12,
            'FIL-AIR-001' => 8,
            'FIL-HAB-001' => 6,
            'FIL-COM-001' => 4,
            'FRE-PAS-001' => 6,
            'FRE-DIS-001' => 2,
            'FRE-LIQ-001' => 10,
            'LUB-5W30-001' => 60,
            'REF-REF-001' => 3.5,
            'SUS-AMO-001' => 8,
            'SUS-BUJ-001' => 6,
            'MOT-BUJ-001' => 24,
            'MOT-COR-001' => 2,
            'ELE-BAT-001' => 4,
            'ELE-LAM-001' => 20,
            'NEU-205-001' => 8,
        ];

        foreach ($initialStock as $sku => $quantity) {
            $part = Part::where('sku', $sku)->first();

            if (!$part || $part->status === PartStatus::DISCONTINUED || $part->movements()->exists()) {
                continue;
            }

            // Antes que los escenarios del taller, para que el kardex quede en orden cronológico.
            ExampleScenarios::travel(ExampleScenarios::at(['days' => 100], 0, 7), fn () => $inventoryService->registerEntry([
                'part_id' => $part->id,
                'type' => InventoryMovementType::INITIAL->value,
                'quantity' => $quantity,
                'unit_cost' => $part->cost_price,
                'reason' => 'Carga inicial de datos de ejemplo.',
            ], $user));
        }
    }
}

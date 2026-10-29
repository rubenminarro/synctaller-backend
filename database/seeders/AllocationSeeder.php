<?php

namespace Database\Seeders;

use App\Models\WorkOrder;
use App\Services\AllocationService;
use App\Services\OwnerShareService;
use Illuminate\Database\Seeder;

/**
 * Reparto económico de las OT completadas de un siniestro (M8), por
 * AllocationService con el cajero, después de completar la OT. C05 queda con
 * las tres versiones posibles: la 1 sustituida, la 2 confirmada (corrige la
 * nota) y la 3 en borrador. Va después de WorkOrderSeeder. Idempotente: omite la
 * OT que ya tiene reparto.
 */
class AllocationSeeder extends Seeder
{
    private const CASHIER = 'caja1@mail.com.py';

    public function run(AllocationService $allocationService, OwnerShareService $ownerShare): void
    {
        foreach (ExampleScenarios::chronological() as $scenario) {
            $workOrder = isset($scenario['work_order']) ? BudgetSeeder::of($scenario)?->workOrder : null;

            if (!$workOrder instanceof WorkOrder || !$workOrder->closing || $workOrder->allocations()->exists() || !$ownerShare->requiresAllocation($workOrder)) {
                continue;
            }

            $cashier = ExampleScenarios::user(self::CASHIER);
            $at = fn (int $offset, int $hour) => ExampleScenarios::afterCompletion($scenario, $offset, $hour);

            ExampleScenarios::travel($at(1, 9), function () use ($allocationService, $workOrder, $cashier) {
                $first = $allocationService->generate($workOrder, $cashier);
                $allocationService->confirm($first, $cashier);
            });

            ExampleScenarios::travel($at(1, 11), function () use ($allocationService, $workOrder, $cashier) {
                $second = $allocationService->generate($workOrder, $cashier);
                $allocationService->update($second, ['notes' => 'Corrige la referencia del perito.']);
                $allocationService->confirm($second, $cashier);
            });

            ExampleScenarios::travel($at(1, 15), fn () => $allocationService->generate($workOrder, $cashier));
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Budget;
use App\Services\BudgetService;

/**
 * Un presupuesto por escenario que lo tiene (ExampleScenarios), creado por su
 * recepcionista por el Service (genera el código y pone la recepción "en
 * proceso"). BudgetItemSeeder carga los ítems y lo lleva a su estado final.
 */
class BudgetSeeder extends Seeder
{
    public function run(BudgetService $budgetService): void
    {
        foreach (ExampleScenarios::chronological() as $scenario) {
            $reception = ExampleScenarios::reception($scenario);

            // Idempotente: una recepción que ya tiene presupuestos (incluso eliminados) se omite.
            if (!isset($scenario['budget']) || !$reception || Budget::withTrashed()->where('reception_id', $reception->id)->exists()) {
                continue;
            }

            ExampleScenarios::travel(ExampleScenarios::at($scenario, 0, 15), fn () => $budgetService->create(
                ['reception_id' => $reception->id, 'notes' => "Presupuesto por: {$scenario['problem']}"],
                ExampleScenarios::user($scenario['receptionist'])->id
            ));
        }
    }

    /**
     * Presupuesto de un escenario.
     */
    public static function of(array $scenario): ?Budget
    {
        $reception = ExampleScenarios::reception($scenario);

        return $reception ? Budget::where('reception_id', $reception->id)->orderBy('id')->first() : null;
    }
}

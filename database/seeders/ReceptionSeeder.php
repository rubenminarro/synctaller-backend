<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Reception;
use App\Models\ServiceCategory;
use App\Models\Vehicle;
use App\Models\ReceptionCheckList;
use App\Services\ReceptionChecklistService;
use Illuminate\Support\Facades\DB;

/**
 * Una recepción por escenario (ExampleScenarios), cargada por su recepcionista
 * en la fecha de llegada, con su checklist. El estado lo derivan después los
 * presupuestos y las OT; las cancelaciones las hace ReceptionCancellationSeeder.
 */
class ReceptionSeeder extends Seeder
{
    public function run(ReceptionChecklistService $checklistService): void
    {
        foreach (ExampleScenarios::chronological() as $scenario) {
            $vehicle = Vehicle::where('plate', $scenario['plate'])->first();

            // Idempotente: la visita del escenario ya existe (aunque esté eliminada).
            if (!$vehicle || ExampleScenarios::reception($scenario)) {
                continue;
            }

            $arrival = ExampleScenarios::at($scenario, 0, 8, 30);

            ExampleScenarios::travel($arrival, function () use ($scenario, $vehicle, $arrival, $checklistService) {
                DB::transaction(function () use ($scenario, $vehicle, $arrival, $checklistService) {
                    $reception = Reception::create([
                        'client_id' => $vehicle->client_id,
                        'vehicle_id' => $vehicle->id,
                        'created_by' => ExampleScenarios::user($scenario['receptionist'])->id,
                        'reception_date' => $arrival,
                        'estimated_delivery_date' => $arrival->addDays(count($scenario['diagnostics']) > 1 ? 7 : 3),
                        // El kilometraje actual del vehículo menos lo que anduvo desde esta visita.
                        'mileage' => max(0, (int) $vehicle->mileage - $scenario['days'] * 40),
                        'fuel_level' => $scenario['fuel'],
                        'problem_description' => $scenario['problem'],
                        'observations' => $scenario['observations'] ?? null,
                    ]);

                    $serviceCategoryIds = ServiceCategory::whereIn('code', $scenario['categories'])->pluck('id')->all();
                    $reception->serviceCategories()->sync($serviceCategoryIds);

                    $checkList = ReceptionCheckList::create(['reception_id' => $reception->id]);
                    $checklistService->generateChecklistItems($checkList, $serviceCategoryIds);
                });
            });
        }
    }
}

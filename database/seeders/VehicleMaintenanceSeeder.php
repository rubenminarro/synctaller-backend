<?php

namespace Database\Seeders;

use App\Models\MaintenancePlan;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Services\MaintenanceService;
use Illuminate\Database\Seeder;

/**
 * Mantenimientos registrados a mano (hechos antes o en otro taller) para tener
 * cada estado: vencidos y por vencer, por días y por kilómetros, y un intervalo
 * propio para un vehículo de flota. Los "al día" los registran solas las OT
 * completadas (MaintenanceService::registerFromWorkOrder); los vehículos sin
 * registros quedan "sin datos".
 */
class VehicleMaintenanceSeeder extends Seeder
{
    public function run(MaintenanceService $maintenanceService): void
    {
        // [placa, plan, hace cuántos días se hizo, kilómetros recorridos desde entonces, intervalo propio (km, días)]
        $records = [
            ['QAB116', 'Cambio de aceite', 200, 4000, null],            // Vencido por días (cada 180).
            ['QAB117', 'Alineacion y balanceo', 400, 9000, null],       // Vencido por días (cada 365).
            ['QAB118', 'Cambio de aceite', 165, 3000, null],            // Por vencer (faltan menos de 30 días).
            ['QAB119', 'Rotacion de neumaticos', 120, 9500, null],      // Por vencer por kilómetros (cada 10.000).
            ['QAB124', 'Cambio de aceite', 100, 3500, [3000, 90]],      // Flota: intervalo propio, vencido.
        ];

        foreach ($records as [$plate, $planName, $days, $kmSince, $interval]) {
            $vehicle = Vehicle::where('plate', $plate)->first();
            $plan = MaintenancePlan::where('name', $planName)->first();

            // Idempotente: un vehículo con ese plan ya registrado se omite.
            if (!$vehicle || !$plan || VehicleMaintenance::where('vehicle_id', $vehicle->id)->where('maintenance_plan_id', $plan->id)->exists()) {
                continue;
            }

            $doneAt = ExampleScenarios::at(['days' => $days], 0, 10);

            if ($interval) {
                ExampleScenarios::travel($doneAt->subHour(), fn () => $maintenanceService->configure($vehicle, $plan, [
                    'interval_km' => $interval[0],
                    'interval_days' => $interval[1],
                    'notes' => 'Vehículo de flota: uso intensivo.',
                ]));
            }

            ExampleScenarios::travel($doneAt, fn () => $maintenanceService->recordDone($vehicle, $plan, [
                'done_at' => $doneAt->toDateString(),
                'mileage' => max(0, (int) $vehicle->mileage - $kmSince),
                'notes' => 'Registrado por el cliente: hecho en otro taller.',
            ]));
        }
    }
}

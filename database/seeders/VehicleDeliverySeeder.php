<?php

namespace Database\Seeders;

use App\Models\VehicleDelivery;
use App\Services\VehicleDeliveryService;
use Illuminate\Database\Seeder;

/**
 * Entregas de los vehículos con la factura emitida (y cobrada si es al
 * contado), registradas por la recepcionista (ExampleScenarios::BILLING). Una
 * se anula (solo el administrador puede) y se vuelve a entregar. Las
 * terminadas sin entrega quedan "listas para retirar".
 */
class VehicleDeliverySeeder extends Seeder
{
    public function run(VehicleDeliveryService $deliveryService): void
    {
        foreach (ExampleScenarios::chronological() as $scenario) {
            $reception = isset($scenario['billing']['delivery']) ? ExampleScenarios::reception($scenario) : null;

            // Idempotente: una recepción con entregas (aun anuladas) no se vuelve a entregar.
            if (!$reception || VehicleDelivery::where('reception_id', $reception->id)->exists()) {
                continue;
            }

            $billing = $scenario['billing'];
            $receptionist = ExampleScenarios::user($scenario['receptionist']);
            $client = $reception->client;
            $data = [
                'final_mileage' => $reception->mileage + 12,
                'fuel_level' => $reception->getRawOriginal('fuel_level'),
                'received_by_name' => trim("{$client->first_name} {$client->last_name}"),
                'received_by_document' => $client->document_number,
                'customer_accepted' => true,
                'observations' => 'Retiró conforme.',
            ];

            $delivery = ExampleScenarios::travel(
                ExampleScenarios::afterCompletion($scenario, $billing['delivery'], 16),
                fn () => $deliveryService->deliver($reception, $data, null, $receptionist)
            );

            if (isset($billing['redeliver'])) {
                ExampleScenarios::travel(
                    ExampleScenarios::afterCompletion($scenario, $billing['delivery'], 17),
                    fn () => $deliveryService->cancel($delivery, $billing['redeliver'], ExampleScenarios::user('admin@mail.com.py'))
                );

                // La recepción vuelve a "terminada" y se entrega de nuevo con el kilometraje correcto.
                ExampleScenarios::travel(
                    ExampleScenarios::afterCompletion($scenario, $billing['delivery'], 18),
                    fn () => $deliveryService->deliver($reception->refresh(), [...$data, 'final_mileage' => $reception->mileage + 15], null, $receptionist)
                );
            }
        }
    }
}

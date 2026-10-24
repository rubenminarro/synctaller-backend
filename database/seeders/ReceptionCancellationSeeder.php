<?php

namespace Database\Seeders;

use App\Enums\ReceptionStatus;
use App\Services\ReceptionStatusService;
use Illuminate\Database\Seeder;

/**
 * Cancela las recepciones de los escenarios que lo indican (ExampleScenarios),
 * por la recepcionista y con motivo. Va después de las OT: una recepción solo
 * se cancela sin OT vigentes, y sus presupuestos en borrador o enviados quedan
 * cancelados.
 */
class ReceptionCancellationSeeder extends Seeder
{
    public function run(ReceptionStatusService $receptionStatusService): void
    {
        foreach (ExampleScenarios::chronological() as $scenario) {
            $reception = isset($scenario['cancel']) ? ExampleScenarios::reception($scenario) : null;

            // Idempotente: una recepción ya cancelada no se toca.
            if (!$reception || $reception->status === ReceptionStatus::CANCELLED) {
                continue;
            }

            ExampleScenarios::travel(ExampleScenarios::at($scenario, 2, 12), fn () => $receptionStatusService->cancel(
                $reception,
                $scenario['cancel'],
                ExampleScenarios::user($scenario['receptionist'])
            ));
        }
    }
}

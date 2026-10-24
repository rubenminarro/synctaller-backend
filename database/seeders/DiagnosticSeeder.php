<?php

namespace Database\Seeders;

use App\Enums\DiagnosticStatus;
use App\Enums\Priority;
use App\Models\Diagnostic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Diagnósticos de cada escenario (ExampleScenarios), hechos por su mecánico el
 * día de la llegada, en cada estado: pendiente, en curso, completado y
 * cancelado. Sus ítems los carga DiagnosticItemSeeder.
 */
class DiagnosticSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ExampleScenarios::chronological() as $scenario) {
            $reception = ExampleScenarios::reception($scenario);

            if (!$reception) {
                continue;
            }

            foreach ($scenario['diagnostics'] as $index => [$mechanicCode, $status, $priority, $complaint, $diagnosis, $recommendation, $items]) {
                $templates = array_map(fn (string $key) => DiagnosticItemSeeder::TEMPLATES[$key], $items);
                $status = DiagnosticStatus::from($status);

                ExampleScenarios::travel(ExampleScenarios::at($scenario, 0, 10 + $index), function () use (
                    $reception, $mechanicCode, $status, $priority, $complaint, $diagnosis, $recommendation, $templates, $scenario, $index
                ) {
                    DB::transaction(fn () => Diagnostic::firstOrCreate(
                        // Idempotente: un diagnóstico por queja en cada recepción.
                        ['reception_id' => $reception->id, 'customer_complaint' => $complaint],
                        [
                            'mechanic_id' => ExampleScenarios::mechanic($mechanicCode)->id,
                            'diagnosis' => $diagnosis,
                            'recommendation' => $recommendation,
                            'priority' => Priority::from($priority)->value,
                            'status' => $status->value,
                            'requires_parts' => collect($templates)->contains('requires_replacement', true),
                            'requires_repair' => collect($templates)->contains('requires_repair', true) || $templates === [],
                            'diagnosed_at' => $status === DiagnosticStatus::COMPLETED ? ExampleScenarios::at($scenario, 0, 12 + $index) : null,
                        ]
                    ));
                });
            }
        }
    }
}

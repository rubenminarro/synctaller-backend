<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Part;
use App\Models\Service;
use App\Enums\BudgetItemResponsibility;
use App\Enums\BudgetItemType;
use App\Enums\ClientDecisionChannel;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;

/**
 * Ítems de cada presupuesto de ejemplo (servicios y repuestos del catálogo, o
 * mano de obra libre), cargados como lo hace la API, y su recorrido hasta el
 * estado final del escenario: borrador, enviado, aprobado, rechazado,
 * rechazado y reabierto, o cancelado.
 */
class BudgetItemSeeder extends Seeder
{
    /** Escenarios con siniestro abierto cuyas líneas paga la aseguradora. */
    private const INSURED = ['C07'];

    public function run(BudgetService $budgetService): void
    {
        foreach (ExampleScenarios::chronological() as $scenario) {
            $budget = isset($scenario['budget']) ? BudgetSeeder::of($scenario) : null;

            // Idempotente: solo carga ítems (y mueve el estado) si el presupuesto sigue vacío.
            if (!$budget || $budget->items()->exists()) {
                continue;
            }

            ExampleScenarios::travel(ExampleScenarios::at($scenario, 0, 16), function () use ($budget, $scenario, $budgetService) {
                DB::transaction(function () use ($budget, $scenario, $budgetService) {
                    foreach ($scenario['budget']['items'] as $item) {
                        // Igual que BudgetItemController::store.
                        $data = $budgetService->prepareCatalogItem(self::itemData($item));
                        // C07 tiene un siniestro abierto (ClaimSeeder): su reparación la cubre la aseguradora (M5).
                        if (in_array($scenario['key'], self::INSURED, true)) {
                            $data['responsibility'] = BudgetItemResponsibility::INSURER->value;
                            $budgetService->ensureResponsibilityAllowed($budget, $data);
                        }
                        $data['total'] = $budgetService->itemTotal($data['quantity'], $data['unit_price']);
                        $budget->items()->create($data);
                    }

                    $budgetService->recalculateBudget($budget);
                });
            });

            $status = $scenario['budget']['status'];
            $at = fn (int $offset, int $hour) => ExampleScenarios::at($scenario, $offset, $hour);

            if ($status === 'draft') {
                continue;
            }

            // Enviar exige los diagnósticos cerrados: los escenarios enviados los tienen completados o cancelados.
            ExampleScenarios::travel($at(1, 9), fn () => $budgetService->send($budget->refresh()));

            // La respuesta del cliente la registra el recepcionista del escenario (budget_client_acceptances).
            $receptionist = ExampleScenarios::user($scenario['receptionist']);
            $approval = ['channel' => ClientDecisionChannel::WHATSAPP->value];
            $rejection = ['channel' => ClientDecisionChannel::PHONE->value, 'notes' => 'El cliente no aceptó el precio.'];

            match ($status) {
                'approved' => ExampleScenarios::travel($at(1, 16), fn () => $budgetService->approve($budget->refresh(), $receptionist, $approval)),
                'rejected' => ExampleScenarios::travel($at(1, 16), fn () => $budgetService->reject($budget->refresh(), $receptionist, $rejection)),
                'cancelled' => ExampleScenarios::travel($at(2, 9), fn () => $budgetService->cancel($budget->refresh())),
                'reopened' => [
                    ExampleScenarios::travel($at(1, 16), fn () => $budgetService->reject($budget->refresh(), $receptionist, $rejection)),
                    ExampleScenarios::travel($at(2, 10), fn () => $budgetService->reopen($budget->refresh())),
                ],
                default => null,
            };
        }
    }

    /**
     * [service, nombre] | [part, SKU, cantidad] | [labor, descripción, precio] → datos del ítem como los envía la API.
     */
    private static function itemData(array $item): array
    {
        return match ($item[0]) {
            'service' => [
                'type' => BudgetItemType::SERVICE->value,
                'service_id' => Service::where('name', $item[1])->firstOrFail()->id,
                'description' => $item[1],
                'quantity' => 1,
            ],
            'part' => (function () use ($item) {
                $part = Part::where('sku', $item[1])->firstOrFail();

                return [
                    'type' => BudgetItemType::PART->value,
                    'part_id' => $part->id,
                    'description' => $part->name,
                    'quantity' => $item[2],
                    'unit_price' => $part->sale_price,
                ];
            })(),
            'labor' => [
                'type' => BudgetItemType::LABOR->value,
                'description' => $item[1],
                'quantity' => 1,
                'unit_price' => $item[2],
            ],
        };
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Part;
use App\Models\Service;
use App\Enums\BudgetItemResponsibility;
use App\Enums\BudgetItemType;
use App\Enums\ClientDecisionChannel;
use App\Enums\ClaimDocumentType;
use App\Enums\InsurerAuthorizationDecision;
use App\Models\Budget;
use App\Models\Claim;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\ClaimDocumentService;
use App\Services\InsurerAuthorizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Ítems de cada presupuesto de ejemplo (servicios y repuestos del catálogo, o
 * mano de obra libre), cargados como lo hace la API, y su recorrido hasta el
 * estado final del escenario: borrador, enviado, aprobado, rechazado,
 * rechazado y reabierto, o cancelado. Con siniestro, la autorización de la
 * aseguradora se registra después del envío (M6): C05 queda con la versión 2
 * vigente (subió un importe, con el documento como evidencia) y la de C07 se
 * invalida al reabrir el presupuesto.
 */
class BudgetItemSeeder extends Seeder
{
    /** Escenarios con siniestro abierto cuyas líneas paga la aseguradora. */
    private const INSURED = ['C05', 'C07'];

    public function run(
        BudgetService $budgetService,
        InsurerAuthorizationService $authorizationService,
        ClaimDocumentService $documentService
    ): void {
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
                        // C05 y C07 tienen un siniestro abierto (ClaimSeeder): su reparación la cubre la aseguradora (M5).
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

            if (in_array($scenario['key'], self::INSURED, true)) {
                $this->authorize($scenario, $budget, $receptionist, $authorizationService, $documentService);
            }
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
     * Autorización de la aseguradora tras el envío (M6). C07: total, que se invalida al reabrir. C05: la primera
     * versión cubre el servicio solo en parte; la segunda lo cubre entero con el documento de la aseguradora.
     */
    private function authorize(
        array $scenario,
        Budget $budget,
        User $user,
        InsurerAuthorizationService $authorizationService,
        ClaimDocumentService $documentService
    ): void {
        $at = fn (int $offset, int $hour) => ExampleScenarios::at($scenario, $offset, $hour);
        $items = $budget->items()->get();
        $base = ['amounts_include_tax' => false, 'channel' => ClientDecisionChannel::EMAIL->value, 'adjuster_name' => 'Ramón Giménez'];
        $all = fn (InsurerAuthorizationDecision $decision) => $items->map(fn ($item) => [
            'budget_item_id' => $item->id,
            'decision' => $decision->value,
        ])->all();

        if ($scenario['key'] === 'C07') {
            ExampleScenarios::travel($at(1, 12), fn () => $authorizationService->register($budget, [
                ...$base, 'decided_at' => now()->toDateString(), 'authorization_number' => 'AUT-0457-1',
                'items' => $all(InsurerAuthorizationDecision::AUTHORIZED),
            ], $user));

            return;
        }

        // C05: amortiguadores autorizados; la instalación, a la mitad.
        $service = $items->firstWhere('type', BudgetItemType::SERVICE);
        $partial = collect($all(InsurerAuthorizationDecision::AUTHORIZED))->map(fn (array $line) => $line['budget_item_id'] === $service->id
            ? [...$line, 'decision' => InsurerAuthorizationDecision::PARTIAL->value, 'authorized_total' => bcdiv((string) $service->total, '2', 2)]
            : $line)->all();

        ExampleScenarios::travel($at(1, 12), fn () => $authorizationService->register($budget, [
            ...$base, 'decided_at' => now()->toDateString(), 'authorization_number' => 'AUT-0472-1', 'items' => $partial,
        ], $user));

        ExampleScenarios::travel($at(2, 11), function () use ($budget, $user, $base, $all, $authorizationService, $documentService) {
            $path = tempnam(sys_get_temp_dir(), 'seed');
            file_put_contents($path, "%PDF-1.4\n% Ampliación de la autorización (ejemplo)\n%%EOF\n");
            $claim = Claim::where('reception_id', $budget->reception_id)->sole();
            $document = $documentService->store($claim, new UploadedFile($path, 'ampliacion-AUT-0472.pdf', null, null, true), [
                'type' => ClaimDocumentType::AUTHORIZATION->value,
                'notes' => 'La aseguradora amplía la cobertura de la instalación.',
            ], $user);
            @unlink($path);

            $authorizationService->register($budget, [
                ...$base, 'decided_at' => now()->toDateString(), 'authorization_number' => 'AUT-0472-2',
                'items' => $all(InsurerAuthorizationDecision::AUTHORIZED), 'document_ids' => [$document->id],
            ], $user);
        });
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

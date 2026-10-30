<?php

namespace Tests\Feature\Claims;

use App\Models\Brand;
use App\Models\Budget;
use App\Models\Claim;
use App\Models\Client;
use App\Models\Reception;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\BudgetService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Invoices\InvoiceTestCase;

/**
 * Base de los tests de siniestros: aseguradora, recepción abierta y cuerpo del alta.
 */
abstract class ClaimTestCase extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function insurer(): int
    {
        return $this->postJson('/api/insurers', ['client' => [
            'document_number' => '8009' . random_int(1000, 9999) . '-' . random_int(1, 9),
            'business_name' => 'Seguros del Sur S.A.',
            'email' => Str::lower(Str::random(8)) . '@example.com',
            'phone' => '+595211' . random_int(100000, 999999),
        ]])->assertCreated()->json('data.id');
    }

    protected function openReception(): Reception
    {
        $brand = Brand::create(['name' => 'Marca ' . Str::random(6)]);
        $client = Client::create([
            'document_number' => '4' . random_int(100000, 999999),
            'first_name' => 'Ana',
            'last_name' => 'Gomez',
            'phone' => '0981' . random_int(100000, 999999),
            // El email es único (D7): el primer cliente del test es "ana@example.com", los demás, otro.
            'email' => Client::where('email', 'ana@example.com')->exists() ? Str::lower(Str::random(10)) . '@example.com' : 'ana@example.com',
        ]);
        $vehicle = Vehicle::create([
            'client_id' => $client->id,
            'brand_id' => $brand->id,
            'vehicle_model_id' => VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Modelo ' . Str::random(6)])->id,
            'plate' => Str::upper(Str::random(7)),
        ]);

        return Reception::create(['client_id' => $client->id, 'vehicle_id' => $vehicle->id, 'reception_date' => now()->toDateString()]);
    }

    protected function claimBody(int $receptionId, int $insurerId, array $overrides = []): array
    {
        return [
            'reception_id' => $receptionId,
            'insurer_id' => $insurerId,
            'claim_type' => 'own',
            'loss_date' => now()->subDays(3)->toDateString(),
            'claim_number' => 'S-' . random_int(10000, 99999),
            ...$overrides,
        ];
    }

    /**
     * Ejemplo 1 (sin IVA): chapería 1.000.000 (autorizada 800.000), paragolpes de catálogo 2 × 500.000 (autorizado),
     * pintura 600.000 (no cubierta), las tres a cargo de la aseguradora; alineación 150.000 del dueño; un extra aprobado
     * de 4 × 5.000. El dueño aprueba con la autorización ya registrada (950.000). Venta: 2.770.000.
     *
     * @return array{0: array, 1: Budget, 2: Claim}
     */
    protected function completedClaimWorkOrder(int $bumpers = 2, bool $authorize = true, int|null $deductible = 0, ?Reception $reception = null): array
    {
        $reception ??= $this->openReception();
        $claim = Claim::where('reception_id', $reception->id)->where('status', 'open')->first()
            ?? Claim::findOrFail($this->postJson('/api/claims', $this->claimBody($reception->id, $this->insurer(), ['deductible_amount' => $deductible]))->assertCreated()->json('data.id'));

        // El concepto de un repuesto lleva el nombre del repuesto (como la línea de la factura).
        $part = $this->createPartViaApi(null, ['name' => 'Paragolpes']);
        $this->entry($part['id'], 10, 300000, ['type' => 'initial'])->assertCreated();
        $clip = $this->createPartViaApi(null, ['name' => 'Clips de moldura']);
        $this->entry($clip['id'], 10, 1000, ['type' => 'initial'])->assertCreated();

        $budgetId = app(BudgetService::class)->create(['reception_id' => $reception->id], User::firstOrFail()->id)->id;
        foreach ([
            ['type' => 'labor', 'description' => 'Chapería de puerta', 'quantity' => 1, 'unit_price' => 1000000, 'responsibility' => 'insurer'],
            ['type' => 'part', 'part_id' => $part['id'], 'description' => 'Paragolpes', 'quantity' => 2, 'unit_price' => 500000, 'responsibility' => 'insurer'],
            ['type' => 'labor', 'description' => 'Pintura', 'quantity' => 1, 'unit_price' => 600000, 'responsibility' => 'insurer'],
            ['type' => 'labor', 'description' => 'Alineación', 'quantity' => 1, 'unit_price' => 150000],
        ] as $line) {
            $this->postJson("/api/budgets/{$budgetId}/items", $line)->assertCreated();
        }
        $this->postJson("/api/budgets/{$budgetId}/send")->assertOk();
        $budget = Budget::with('items')->findOrFail($budgetId);

        if ($authorize) {
            $decisions = ['Chapería de puerta' => ['decision' => 'partial', 'authorized_total' => 800000], 'Paragolpes' => ['decision' => 'authorized'], 'Pintura' => ['decision' => 'not_covered']];
            $this->postJson("/api/budgets/{$budgetId}/insurer-authorizations", [
                'amounts_include_tax' => false,
                'decided_at' => now()->toDateString(),
                'channel' => 'email',
                'items' => $budget->items->where('responsibility.value', 'insurer')->values()
                    ->map(fn ($item) => ['budget_item_id' => $item->id, ...$decisions[$item->description]])->all(),
            ])->assertCreated();
        }

        $this->postJson("/api/budgets/{$budgetId}/approve", ['channel' => 'whatsapp'])->assertOk();

        if (!$authorize) {
            $this->actingAsRole('jefe-taller');
            $exception = $this->postJson('/api/internal-exceptions', ['type' => 'start_without_authorization', 'subject_id' => $budgetId, 'reason' => 'El perito confirma por teléfono.'])->assertCreated()->json('data.id');
            $workOrder = $this->postJson('/api/work-orders', ['budget_id' => $budgetId, 'mechanic_id' => $this->createMechanic()->id, 'internal_exception_id' => $exception])->assertCreated()->json('data');
            $this->actingAsAdmin();
        } else {
            $workOrder = $this->createWorkOrder($budgetId);
        }

        $this->startWorkOrder($workOrder);
        $extra = $this->addApprovedExtra($workOrder, ['part_id' => $clip['id'], 'quantity' => 4, 'unit_price' => 5000]);
        $budgeted = collect($workOrder['parts'])->firstWhere('is_budgeted', true);
        $this->postJson($this->partsUrl($workOrder, $budgeted['id'], 'consume'), ['quantity_used' => $bumpers])->assertOk();
        $this->postJson($this->partsUrl($workOrder, $extra['id'], 'consume'), ['quantity_used' => 4])->assertOk();
        $this->completeAllItems($workOrder);
        $this->postJson("/api/work-orders/{$workOrder['id']}/complete")->assertOk();

        return [$workOrder, $budget, $claim->refresh()];
    }

    protected function url(array $workOrder, ?int $allocationId = null, string $action = ''): string
    {
        return "/api/work-orders/{$workOrder['id']}/allocations" . ($allocationId ? "/{$allocationId}" : '') . ($action ? "/{$action}" : '');
    }
}

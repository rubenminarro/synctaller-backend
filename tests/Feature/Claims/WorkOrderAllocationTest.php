<?php

namespace Tests\Feature\Claims;

use App\Exceptions\AllocationException;
use App\Models\Budget;
use App\Models\Claim;
use App\Models\InternalException;
use App\Models\Reception;
use App\Models\User;
use App\Models\WorkOrderAllocation;
use App\Services\BudgetService;

/**
 * Reparto económico de la OT de un siniestro (M8): propuesta desde el cierre y la
 * autorización, edición, confirmación con R1–R7, versiones, deducible (capa B) y
 * los efectos sobre la factura y el siniestro. Ejemplos 1, 2 y 4 de la
 * especificación.
 */
class WorkOrderAllocationTest extends ClaimTestCase
{
    /**
     * Ejemplo 1 (sin IVA): chapería 1.000.000 (autorizada 800.000), paragolpes de catálogo 2 × 500.000 (autorizado),
     * pintura 600.000 (no cubierta), las tres a cargo de la aseguradora; alineación 150.000 del dueño; un extra aprobado
     * de 4 × 5.000. El dueño aprueba con la autorización ya registrada (950.000). Venta: 2.770.000.
     *
     * @return array{0: array, 1: Budget, 2: Claim}
     */
    private function completedClaimWorkOrder(int $bumpers = 2, bool $authorize = true, int|null $deductible = 0, ?Reception $reception = null): array
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

    private function url(array $workOrder, ?int $allocationId = null, string $action = ''): string
    {
        return "/api/work-orders/{$workOrder['id']}/allocations" . ($allocationId ? "/{$allocationId}" : '') . ($action ? "/{$action}" : '');
    }

    /** Porciones del borrador como las acepta el PATCH, reemplazando las indicadas por descripción y tipo. */
    private function linesBody(array $allocation, array $replace = []): array
    {
        return collect($allocation['lines'])
            ->map(fn (array $line) => [
                'source_type' => $line['source']['type'],
                'source_id' => $line['source']['id'],
                'portion_type' => $line['portion_type']['value'],
                'amount' => $line['amount'],
                'description' => $line['description'],
            ])
            ->flatMap(fn (array $line) => $replace[$line['description'] . '|' . $line['portion_type']] ?? [$line])
            ->map(fn (array $line) => collect($line)->except('description')->all())
            ->values()
            ->all();
    }

    private function amounts(array $allocation): array
    {
        return collect($allocation['lines'])
            ->mapWithKeys(fn (array $line) => [$line['description'] . '|' . $line['portion_type']['value'] => $line['amount']])
            ->sortKeys()
            ->all();
    }

    public function test_example_1_is_proposed_and_confirmed(): void
    {
        $this->actingAsAdmin();
        [$workOrder, , $claim] = $this->completedClaimWorkOrder();

        $this->actingAsRole('cajero');
        $draft = $this->postJson($this->url($workOrder))
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status.value', 'draft')
            ->assertJsonPath('data.sale_total', '2770000.00')
            ->assertJsonPath('data.deductible_amount', '0.00')
            ->assertJsonPath('data.summary.total', '2770000.00')
            ->assertJsonPath('data.summary.deductible_fiscal_pending', false)
            ->json('data');

        $expected = [
            'Alineación|owner_responsibility' => '150000.00',
            'Chapería de puerta|insurer_covered' => '800000.00',
            'Chapería de puerta|owner_uncovered' => '200000.00',
            'Clips de moldura|owner_responsibility' => '20000.00',
            'Paragolpes|insurer_covered' => '1000000.00',
            'Pintura|owner_uncovered' => '600000.00',
        ];
        ksort($expected);
        $this->assertSame($expected, $this->amounts($draft));
        $this->assertSame(['insurer' => '1800000.00', 'owner' => '970000.00'], collect($draft['summary']['responsibles'])->pluck('amount', 'role')->sortKeys()->all());
        $this->assertTrue(collect($draft['checks'])->every(fn (array $check) => $check['ok']));

        $this->postJson($this->url($workOrder))->assertStatus(409)->assertJsonPath('error_code', 'ALLOCATION_DRAFT_EXISTS');

        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'confirmed')
            ->assertJsonPath('data.checks', null);

        // La OT lo muestra; una OT con reparto no se factura entera al dueño (R10, M8).
        $this->getJson("/api/work-orders/{$workOrder['id']}")
            ->assertOk()
            ->assertJsonPath('data.requires_allocation', true)
            ->assertJsonPath('data.allocation.version', 1)
            ->assertJsonPath('data.allocation.total', '2770000.00');
        $this->postJson("/api/work-orders/{$workOrder['id']}/invoice")
            ->assertStatus(422)->assertJsonPath('error_code', 'WORK_ORDER_REQUIRES_ALLOCATION_INVOICING');

        // El siniestro no se cancela con lo cubierto confirmado.
        $this->actingAsAdmin();
        $this->postJson("/api/claims/{$claim->id}/cancel", ['reason' => 'La aseguradora desiste.'])
            ->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_HAS_INSURER_ALLOCATIONS');
    }

    public function test_example_2_owner_excess_is_absorbed_or_re_accepted(): void
    {
        $this->actingAsAdmin();
        [$workOrder, $budget] = $this->completedClaimWorkOrder(bumpers: 3);

        // 3 paragolpes: la aseguradora cubre 1.000.000 (su tope) y 500.000 van al dueño, que aceptó 950.000 + 20.000.
        $draft = $this->postJson($this->url($workOrder))->assertCreated()->json('data');
        $this->assertSame('500000.00', $this->amounts($draft)['Paragolpes|owner_uncovered']);
        $r5 = collect($draft['checks'])->firstWhere('rule', 'R5');
        $this->assertFalse($r5['ok']);
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))
            ->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_OWNER_AMOUNT_NOT_ACCEPTED');

        // Salida (b): el taller absorbe los 500.000 con una excepción de la OT.
        $this->actingAsRole('jefe-taller');
        $exception = $this->postJson('/api/internal-exceptions', ['type' => 'workshop_absorption', 'subject_id' => $workOrder['id'], 'amount' => 400000, 'reason' => 'El tercer paragolpes lo rompió el taller.'])
            ->assertCreated()->json('data.id');

        $this->actingAsAdmin();
        $absorbed = fn (int $exceptionId) => $this->linesBody($draft, ['Paragolpes|owner_uncovered' => [[
            'source_type' => 'work_order_part', 'source_id' => collect($draft['lines'])->firstWhere('description', 'Paragolpes')['source']['id'],
            'portion_type' => 'workshop_absorbed', 'amount' => 500000, 'internal_exception_id' => $exceptionId, 'description' => 'Paragolpes',
        ]]]);
        // Una excepción por menos de lo absorbido no alcanza (R6).
        $this->patchJson($this->url($workOrder, $draft['id']), ['lines' => $absorbed($exception)])->assertOk();
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))
            ->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_ABSORPTION_NOT_AUTHORIZED');

        $this->actingAsRole('jefe-taller');
        $enough = $this->postJson('/api/internal-exceptions', ['type' => 'workshop_absorption', 'subject_id' => $workOrder['id'], 'amount' => 500000, 'reason' => 'El tercer paragolpes lo rompió el taller.'])
            ->assertStatus(409)->json('error_code');
        $this->assertSame('INTERNAL_EXCEPTION_ALREADY_GRANTED', $enough);

        // Mientras la primera esté sin usar no se otorga otra: se absorbe lo que cubre (400.000) y el resto lo acepta el
        // dueño (salida a).
        $this->actingAsAdmin();
        $mixed = $this->linesBody($draft, ['Paragolpes|owner_uncovered' => [
            ['source_type' => 'work_order_part', 'source_id' => collect($draft['lines'])->firstWhere('description', 'Paragolpes')['source']['id'], 'portion_type' => 'workshop_absorbed', 'amount' => 400000, 'internal_exception_id' => $exception, 'description' => 'Paragolpes'],
            ['source_type' => 'work_order_part', 'source_id' => collect($draft['lines'])->firstWhere('description', 'Paragolpes')['source']['id'], 'portion_type' => 'owner_uncovered', 'amount' => 100000, 'description' => 'Paragolpes'],
        ]]);
        $this->patchJson($this->url($workOrder, $draft['id']), ['lines' => $mixed])->assertOk();
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))
            ->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_OWNER_AMOUNT_NOT_ACCEPTED');

        // El dueño re-acepta con la OT completada: lo ejecutado sin los extras (1.450.000).
        $this->postJson("/api/budgets/{$budget->id}/client-acceptances", ['channel' => 'phone'])
            ->assertCreated()->assertJsonPath('data.owner_amount', '1450000.00');
        $confirmed = $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))->assertOk()->json('data');

        $this->assertSame(['insurer' => '1800000.00', 'owner' => '1070000.00', 'workshop' => '400000.00'], collect($confirmed['summary']['responsibles'])->pluck('amount', 'role')->sortKeys()->all());
        $consumed = InternalException::findOrFail($exception);
        $this->assertSame('work_order_allocation', $consumed->consumed_by_type);
        $this->assertSame($confirmed['id'], $consumed->consumed_by_id);
    }

    public function test_the_rules_are_checked_on_confirmation(): void
    {
        $this->actingAsAdmin();
        [$workOrder] = $this->completedClaimWorkOrder();
        $draft = $this->postJson($this->url($workOrder))->assertCreated()->json('data');
        $extraName = $this->extraName($draft);
        $source = fn (string $description) => collect($draft['lines'])->firstWhere('description', $description)['source'];

        // Forma: conceptos de esta OT, sin repetir tipo.
        $this->patchJson($this->url($workOrder, $draft['id']), ['lines' => [['source_type' => 'work_order_item', 'source_id' => 999999, 'portion_type' => 'owner_responsibility', 'amount' => 1]]])
            ->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_LINE_MISMATCH');
        $alignment = ['source_type' => $source('Alineación')['type'], 'source_id' => $source('Alineación')['id'], 'portion_type' => 'owner_responsibility', 'amount' => 75000];
        $this->patchJson($this->url($workOrder, $draft['id']), ['lines' => [$alignment, $alignment]])
            ->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_LINE_MISMATCH');
        $this->patchJson($this->url($workOrder, $draft['id']), ['lines' => [[...$alignment, 'portion_type' => 'otro']]])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.portion_type');

        $try = function (array $lines, string $code) use ($workOrder, $draft) {
            $this->patchJson($this->url($workOrder, $draft['id']), ['lines' => $lines])->assertOk();
            $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))->assertStatus($code === 'ALLOCATION_TOTAL_MISMATCH' ? 409 : 422)->assertJsonPath('error_code', $code);
        };

        // R1: falta un concepto.
        $try(collect($this->linesBody($draft))->reject(fn ($line) => $line['source_id'] === $source('Alineación')['id'] && $line['source_type'] === $source('Alineación')['type'])->values()->all(), 'ALLOCATION_INCOMPLETE');
        // R4: el extra, a la aseguradora.
        $try($this->linesBody($draft, ["{$extraName}|owner_responsibility" => [['source_type' => 'work_order_part', 'source_id' => $source($extraName)['id'], 'portion_type' => 'insurer_covered', 'amount' => 20000, 'description' => $extraName]]]), 'ALLOCATION_EXTRA_NOT_COVERABLE');
        // R3: la chapería entera a la aseguradora (autorizó 800.000).
        $try($this->linesBody($draft, [
            'Chapería de puerta|insurer_covered' => [['source_type' => 'work_order_item', 'source_id' => $source('Chapería de puerta')['id'], 'portion_type' => 'insurer_covered', 'amount' => 1000000, 'description' => 'Chapería de puerta']],
            'Chapería de puerta|owner_uncovered' => [],
        ]), 'ALLOCATION_EXCEEDS_AUTHORIZATION');
        // R6: absorción sin excepción.
        $try($this->linesBody($draft, ['Alineación|owner_responsibility' => [[...$alignment, 'portion_type' => 'workshop_absorbed', 'amount' => 150000, 'description' => 'Alineación']]]), 'ALLOCATION_ABSORPTION_NOT_AUTHORIZED');

        // Volver a la propuesta confirma; ya confirmado no se edita, no se elimina ni cambia.
        $this->patchJson($this->url($workOrder, $draft['id']), ['lines' => $this->linesBody($draft)])->assertOk();
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))->assertOk();
        $this->patchJson($this->url($workOrder, $draft['id']), ['notes' => 'Otra nota.'])->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_NOT_EDITABLE');
        $this->deleteJson($this->url($workOrder, $draft['id']))->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_NOT_EDITABLE');

        try {
            WorkOrderAllocation::findOrFail($draft['id'])->update(['sale_total' => 1]);
            $this->fail('Un reparto confirmado es inmutable.');
        } catch (AllocationException $e) {
            $this->assertSame('ALLOCATION_IMMUTABLE', $e->getErrorCode());
        }
    }

    public function test_a_new_version_supersedes_the_confirmed_one(): void
    {
        $this->actingAsAdmin();
        [$workOrder] = $this->completedClaimWorkOrder();
        $first = $this->postJson($this->url($workOrder))->assertCreated()->json('data');
        $this->postJson($this->url($workOrder, $first['id'], 'confirm'))->assertOk();

        // La nueva versión parte de la confirmada; un borrador se puede descartar.
        $second = $this->postJson($this->url($workOrder))->assertCreated()->assertJsonPath('data.version', 2)->json('data');
        $this->assertSame($this->amounts($first), $this->amounts($second));
        $this->deleteJson($this->url($workOrder, $second['id']))->assertOk();
        $this->getJson($this->url($workOrder, $second['id']))->assertNotFound();

        // El borrador descartado no deja rastro: su número de versión se vuelve a usar.
        $third = $this->postJson($this->url($workOrder))->assertCreated()->assertJsonPath('data.version', 2)->json('data');
        $this->patchJson($this->url($workOrder, $third['id']), ['notes' => 'Corrección pedida por la aseguradora.'])->assertOk();
        $this->postJson($this->url($workOrder, $third['id'], 'confirm'))->assertOk();

        $this->getJson($this->url($workOrder))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status.value', 'superseded')
            ->assertJsonPath('data.0.superseded_by_id', $third['id'])
            ->assertJsonPath('data.1.status.value', 'confirmed');

        // Otra OT en la URL → 404 (scopeBindings).
        [$other] = $this->completedClaimWorkOrder();
        $this->getJson($this->url($other, $third['id']))->assertNotFound();
    }

    public function test_the_deductible_layer(): void
    {
        $this->actingAsAdmin();
        [$workOrder, $budget, $claim] = $this->completedClaimWorkOrder(deductible: null);

        // Desconocido no es 0.
        $draft = $this->postJson($this->url($workOrder))->assertCreated()->assertJsonPath('data.deductible_amount', null)->json('data');
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))
            ->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_DEDUCTIBLE_UNKNOWN');

        // Informado después del aprobado: el dueño no lo aceptó todavía.
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_amount' => 300000])->assertOk();
        $this->patchJson($this->url($workOrder, $draft['id']), ['deductible_amount' => 300000])->assertOk();
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))
            ->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_DEDUCTIBLE_NOT_ACCEPTED');
        $this->postJson("/api/budgets/{$budget->id}/client-acceptances", ['channel' => 'phone'])
            ->assertCreated()->assertJsonPath('data.deductible_amount', '300000.00');

        // R7: no más que el del siniestro.
        $this->patchJson($this->url($workOrder, $draft['id']), ['deductible_amount' => 300000.01])->assertOk();
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))
            ->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_DEDUCTIBLE_EXCEEDS_CLAIM');

        $this->patchJson($this->url($workOrder, $draft['id']), ['deductible_amount' => 300000])->assertOk();
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))
            ->assertOk()
            ->assertJsonPath('data.summary.deductible_fiscal_pending', true);

        // El deducible no baja de lo asignado.
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_amount' => 100000])
            ->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_DEDUCTIBLE_BELOW_ALLOCATED');
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_amount' => null])
            ->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_DEDUCTIBLE_BELOW_ALLOCATED');

        // Ejemplo 4: una segunda OT del mismo siniestro (complementario) propone el deducible restante (0) y no puede
        // volver a tomar el deducible ya asignado.
        [$second] = $this->completedClaimWorkOrder(reception: Reception::findOrFail($claim->reception_id));
        $other = $this->postJson($this->url($second))->assertCreated()->assertJsonPath('data.deductible_amount', '0.00')->json('data');
        $this->patchJson($this->url($second, $other['id']), ['deductible_amount' => 300000])->assertOk();
        $this->postJson($this->url($second, $other['id'], 'confirm'))
            ->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_DEDUCTIBLE_EXCEEDS_CLAIM');
        $this->patchJson($this->url($second, $other['id']), ['deductible_amount' => 0])->assertOk();
        $this->postJson($this->url($second, $other['id'], 'confirm'))->assertOk();
    }

    public function test_without_authorization_everything_goes_to_the_owner(): void
    {
        $this->actingAsAdmin();
        [$workOrder] = $this->completedClaimWorkOrder(authorize: false);

        $draft = $this->postJson($this->url($workOrder))
            ->assertCreated()
            ->assertJsonPath('data.summary.warnings.0', 'El presupuesto no tiene una autorización vigente de la aseguradora: lo de sus líneas queda a cargo del dueño hasta registrarla.')
            ->json('data');
        $this->assertSame(['owner' => '2770000.00'], collect($draft['summary']['responsibles'])->pluck('amount', 'role')->all());
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))
            ->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_OWNER_AMOUNT_NOT_ACCEPTED');
    }

    public function test_only_completed_claim_work_orders_are_allocated(): void
    {
        $this->actingAsAdmin();

        // Particular: no hay reparto y la factura es la de siempre.
        $particular = $this->completedWorkOrder();
        $this->postJson($this->url($particular))->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_NOT_REQUIRED');
        $this->getJson("/api/work-orders/{$particular['id']}")->assertOk()->assertJsonPath('data.requires_allocation', false)->assertJsonPath('data.allocation', null);
        $this->generateInvoice($particular);

        // De un siniestro, pero sin completar.
        $reception = $this->openReception();
        $this->postJson('/api/claims', $this->claimBody($reception->id, $this->insurer(), ['deductible_amount' => 0]))->assertCreated();
        $budgetId = app(BudgetService::class)->create(['reception_id' => $reception->id], User::firstOrFail()->id)->id;
        $item = $this->postJson("/api/budgets/{$budgetId}/items", ['type' => 'labor', 'description' => 'Chapería', 'quantity' => 1, 'unit_price' => 100000, 'responsibility' => 'insurer'])->assertCreated()->json('data');
        $this->postJson("/api/budgets/{$budgetId}/send")->assertOk();
        $this->postJson("/api/budgets/{$budgetId}/insurer-authorizations", ['amounts_include_tax' => false, 'decided_at' => now()->toDateString(), 'channel' => 'email', 'items' => [['budget_item_id' => $item['id'], 'decision' => 'authorized']]])->assertCreated();
        $this->postJson("/api/budgets/{$budgetId}/approve")->assertOk();
        $pending = $this->createWorkOrder($budgetId);
        $this->postJson($this->url($pending))->assertStatus(422)->assertJsonPath('error_code', 'WORK_ORDER_NOT_ALLOCATABLE');
    }

    public function test_permissions_by_role(): void
    {
        $this->actingAsAdmin();
        [$workOrder] = $this->completedClaimWorkOrder();
        $id = $this->postJson($this->url($workOrder))->assertCreated()->json('data.id');

        foreach (['recepcionista', 'jefe-taller'] as $role) {
            $this->actingAsRole($role);
            $this->getJson($this->url($workOrder))->assertOk();
            $this->getJson($this->url($workOrder, $id))->assertOk();
            $this->postJson($this->url($workOrder, $id, 'confirm'))->assertForbidden();
            $this->patchJson($this->url($workOrder, $id), ['notes' => 'Nota.'])->assertForbidden();
            $this->deleteJson($this->url($workOrder, $id))->assertForbidden();
        }

        foreach (['deposito', 'mechanic'] as $role) {
            $this->actingAsRole($role);
            $this->getJson($this->url($workOrder))->assertForbidden();
            $this->getJson("/api/work-orders/{$workOrder['id']}")->assertOk()->assertJsonMissingPath('data.allocation');
        }

        $this->actingAsRole('cajero');
        $this->patchJson($this->url($workOrder, $id), ['notes' => 'Revisado con el perito.'])->assertOk();
        $this->postJson($this->url($workOrder, $id, 'confirm'))->assertOk();
    }

    private function extraName(array $allocation): string
    {
        return collect($allocation['lines'])->pluck('description')
            ->first(fn (string $name) => !in_array($name, ['Alineación', 'Chapería de puerta', 'Paragolpes', 'Pintura'], true));
    }
}

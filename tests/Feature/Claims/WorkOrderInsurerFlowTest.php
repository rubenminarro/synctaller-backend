<?php

namespace Tests\Feature\Claims;

use App\Enums\InternalExceptionType;
use App\Exceptions\InternalExceptionException;
use App\Models\Budget;
use App\Models\Claim;
use App\Models\InternalException;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\InternalExceptionService;
use App\Services\OwnerShareService;
use Illuminate\Support\Facades\DB;

/**
 * Bloqueo de la OT y excepciones internas (M7): con líneas de la aseguradora, la
 * OT exige su autorización vigente (o la excepción start_without_authorization)
 * y que la última aceptación del dueño cubra su parte; re-aceptación con
 * evidencia, advertencias de B y P5, y el flujo particular sin cambios.
 */
class WorkOrderInsurerFlowTest extends ClaimTestCase
{
    /**
     * Presupuesto enviado de una recepción con siniestro abierto (sin IVA): paragolpes de catálogo 2 × 100.000 y
     * chapería 500.000 a cargo de la aseguradora; lavado 80.000 del dueño.
     */
    private function insuredBudget(): Budget
    {
        $reception = $this->openReception();
        $this->postJson('/api/claims', $this->claimBody($reception->id, $this->insurer()))->assertCreated();

        $part = $this->createPartViaApi();
        $this->entry($part['id'], 10, 60000, ['type' => 'initial'])->assertCreated();

        $budgetId = app(BudgetService::class)->create(['reception_id' => $reception->id], User::firstOrFail()->id)->id;

        foreach ([
            ['type' => 'part', 'part_id' => $part['id'], 'description' => 'Paragolpes', 'quantity' => 2, 'unit_price' => 100000, 'responsibility' => 'insurer'],
            ['type' => 'labor', 'description' => 'Chapería de puerta', 'quantity' => 1, 'unit_price' => 500000, 'responsibility' => 'insurer'],
            ['type' => 'labor', 'description' => 'Lavado de motor', 'quantity' => 1, 'unit_price' => 80000],
        ] as $line) {
            $this->postJson("/api/budgets/{$budgetId}/items", $line)->assertCreated();
        }

        $this->postJson("/api/budgets/{$budgetId}/send")->assertOk();

        return Budget::with('items')->findOrFail($budgetId);
    }

    /** Autorización: todo autorizado, o la chapería parcial por el importe indicado. */
    private function authorize(Budget $budget, ?int $bodywork = null): int
    {
        $items = $budget->items->where('responsibility.value', 'insurer')->values()->map(fn ($item) => [
            'budget_item_id' => $item->id,
            ...($bodywork !== null && $item->description === 'Chapería de puerta'
                ? ['decision' => 'partial', 'authorized_total' => $bodywork]
                : ['decision' => 'authorized']),
        ])->all();

        return $this->postJson("/api/budgets/{$budget->id}/insurer-authorizations", [
            'amounts_include_tax' => false,
            'decided_at' => now()->toDateString(),
            'channel' => 'email',
            'items' => $items,
        ])->assertCreated()->json('data.id');
    }

    private function createWorkOrderFor(Budget $budget, array $extra = [])
    {
        return $this->postJson('/api/work-orders', [
            'budget_id' => $budget->id,
            'mechanic_id' => $this->createMechanic()->id,
            ...$extra,
        ]);
    }

    private function grant(array $body)
    {
        return $this->postJson('/api/internal-exceptions', ['reason' => 'El perito autorizó por teléfono y la confirma mañana.', ...$body]);
    }

    public function test_the_work_order_requires_the_insurer_authorization(): void
    {
        $this->actingAsAdmin();
        $budget = $this->insuredBudget();

        // Aprobado antes de la autorización: el dueño acepta solo sus líneas (las de la aseguradora quedan pendientes).
        $this->postJson("/api/budgets/{$budget->id}/approve", ['channel' => 'whatsapp'])
            ->assertOk()
            ->assertJsonPath('data.client_acceptances.0.owner_amount', '80000.00')
            ->assertJsonPath('data.client_acceptances.0.insurer_authorization_id', null);

        $this->createWorkOrderFor($budget)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'WORK_ORDER_INSURER_AUTHORIZATION_REQUIRED');

        // Con la autorización total, la parte del dueño sigue en 80.000: la aceptación la cubre.
        $this->authorize($budget);

        $this->createWorkOrderFor($budget)
            ->assertCreated()
            ->assertJsonPath('data.has_claim', true)
            ->assertJsonPath('data.owner_amount_warning', null);
    }

    public function test_a_lower_authorization_needs_a_re_acceptance_that_no_exception_replaces(): void
    {
        $this->actingAsAdmin();
        $budget = $this->insuredBudget();
        $first = $this->authorize($budget);

        $this->postJson("/api/budgets/{$budget->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.client_acceptances.0.owner_amount', '80000.00')
            ->assertJsonPath('data.client_acceptances.0.insurer_authorization_id', $first);

        // Ejemplo 3: la versión 2 baja la chapería a 300.000 y la parte del dueño pasa a 280.000.
        $second = $this->authorize($budget, 300000);
        $this->assertSame('280000.00', app(OwnerShareService::class)->budgetOwnerAmount($budget));

        $this->createWorkOrderFor($budget)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'WORK_ORDER_CLIENT_ACCEPTANCE_OUTDATED');

        // La excepción no lo resuelve: con autorización vigente ni siquiera se otorga.
        $this->grant(['type' => 'start_without_authorization', 'subject_id' => $budget->id])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INTERNAL_EXCEPTION_SUBJECT_INVALID');

        // Re-aceptación: el medio es obligatorio; el importe lo calcula el sistema.
        $url = "/api/budgets/{$budget->id}/client-acceptances";
        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('channel');
        $this->postJson($url, ['channel' => 'phone', 'contact' => 'Ana Gómez', 'notes' => 'Acepta pagar la diferencia de la chapería.'])
            ->assertCreated()
            ->assertJsonPath('data.kind.value', 're_acceptance')
            ->assertJsonPath('data.kind.label', 'Re-aceptación')
            ->assertJsonPath('data.owner_amount', '280000.00')
            ->assertJsonPath('data.insurer_authorization_id', $second)
            ->assertJsonPath('data.channel.value', 'phone');

        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.kind.value', 're_acceptance');
        $this->assertSame('approved', $budget->refresh()->status->value);

        $this->createWorkOrderFor($budget)->assertCreated();
    }

    public function test_the_start_exception_is_granted_and_consumed_once(): void
    {
        $this->actingAsAdmin();
        $budget = $this->insuredBudget();
        $this->postJson("/api/budgets/{$budget->id}/approve")->assertOk();

        $this->actingAsRole('jefe-taller');

        $this->grant(['type' => 'start_without_authorization', 'subject_id' => $budget->id, 'amount' => 1000])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->grant(['type' => 'start_without_authorization', 'subject_id' => $budget->id, 'reason' => 'Corto'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $exception = $this->grant(['type' => 'start_without_authorization', 'subject_id' => $budget->id])
            ->assertCreated()
            ->assertJsonPath('data.type.value', 'start_without_authorization')
            ->assertJsonPath('data.subject.type', 'budget')
            ->assertJsonPath('data.subject.code', $budget->code)
            ->assertJsonPath('data.amount', null)
            ->assertJsonPath('data.consumed_at', null)
            ->json('data');

        $this->grant(['type' => 'start_without_authorization', 'subject_id' => $budget->id])
            ->assertStatus(409)->assertJsonPath('error_code', 'INTERNAL_EXCEPTION_ALREADY_GRANTED');

        // De otro presupuesto no sirve.
        $this->actingAsAdmin();
        $other = $this->insuredBudget();
        $this->postJson("/api/budgets/{$other->id}/approve")->assertOk();
        $this->createWorkOrderFor($other, ['internal_exception_id' => $exception['id']])
            ->assertStatus(422)->assertJsonPath('error_code', 'INTERNAL_EXCEPTION_NOT_APPLICABLE');

        $this->actingAsRole('jefe-taller');
        $workOrder = $this->createWorkOrderFor($budget, ['internal_exception_id' => $exception['id']])
            ->assertCreated()
            ->json('data');

        $this->getJson("/api/internal-exceptions/{$exception['id']}")
            ->assertOk()
            ->assertJsonPath('data.consumed_by.type', 'work_order')
            ->assertJsonPath('data.consumed_by.id', $workOrder['id'])
            ->assertJsonPath('data.consumed_by.code', $workOrder['code']);
        $this->getJson('/api/internal-exceptions?consumed=0')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/internal-exceptions?subject_type=budget&subject_id={$budget->id}")->assertOk()->assertJsonCount(1, 'data');

        // Usada, ya no habilita nada y no cambia.
        $model = InternalException::findOrFail($exception['id']);
        try {
            app(InternalExceptionService::class)->forWorkOrderStart($model->id, $budget);
            $this->fail('Una excepción usada no debe volver a servir.');
        } catch (InternalExceptionException $e) {
            $this->assertSame('INTERNAL_EXCEPTION_ALREADY_CONSUMED', $e->getErrorCode());
        }

        foreach ([fn () => $model->update(['reason' => 'Otro motivo cualquiera']), fn () => $model->delete()] as $change) {
            try {
                $change();
                $this->fail('Una excepción otorgada es inmutable.');
            } catch (InternalExceptionException $e) {
                $this->assertSame('INTERNAL_EXCEPTION_IMMUTABLE', $e->getErrorCode());
            }
        }

        $this->assertSame(1, DB::table('audit_logs')->where('auditable_type', 'internal_exception')
            ->where('auditable_id', $model->id)->where('event', 'created')->count());
    }

    public function test_a_cancelled_claim_puts_everything_on_the_owner(): void
    {
        $this->actingAsAdmin();
        $budget = $this->insuredBudget();
        $this->postJson("/api/budgets/{$budget->id}/approve")->assertOk();

        $claim = Claim::where('reception_id', $budget->reception_id)->sole();
        $this->postJson("/api/claims/{$claim->id}/cancel", ['reason' => 'La aseguradora negó la cobertura.'])->assertOk();

        // Sin siniestro no hace falta autorización, pero el dueño aceptó 80.000 y ahora paga los 780.000.
        $this->createWorkOrderFor($budget)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'WORK_ORDER_CLIENT_ACCEPTANCE_OUTDATED');

        $this->postJson("/api/budgets/{$budget->id}/client-acceptances", ['channel' => 'in_person'])
            ->assertCreated()
            ->assertJsonPath('data.owner_amount', '780000.00')
            ->assertJsonPath('data.insurer_authorization_id', null);

        $this->createWorkOrderFor($budget)->assertCreated()->assertJsonPath('data.has_claim', false);
    }

    public function test_the_work_order_warns_when_the_owner_share_exceeds_the_acceptance(): void
    {
        $this->actingAsAdmin();
        $budget = $this->insuredBudget();
        $this->authorize($budget);
        $this->postJson("/api/budgets/{$budget->id}/approve")->assertOk();
        $workOrder = $this->createWorkOrderFor($budget)->assertCreated()->json('data');
        $this->startWorkOrder($workOrder);

        // Ejemplo 2: se usan 3 paragolpes en lugar de 2; la aseguradora autorizó 200.000 y el resto va al dueño.
        $line = collect($workOrder['parts'])->firstWhere('is_budgeted', true);
        $this->postJson($this->partsUrl($workOrder, $line['id'], 'consume'), ['quantity_used' => 3])->assertOk();

        $this->getJson("/api/work-orders/{$workOrder['id']}")
            ->assertOk()
            ->assertJsonPath('data.owner_amount_warning.accepted_amount', '80000.00')
            ->assertJsonPath('data.owner_amount_warning.potential_amount', '180000.00')
            ->assertJsonPath('data.owner_amount_warning.difference', '100000.00')
            ->assertJsonPath('data.owner_amount_warning.amount_exceeded', true)
            ->assertJsonPath('data.owner_amount_warning.deductible_increased', false);

        // El mecánico ve la OT, pero no la advertencia (datos del siniestro).
        $this->actingAsMechanic();
        $this->getJson("/api/work-orders/{$workOrder['id']}")->assertOk()->assertJsonMissingPath('data.owner_amount_warning');

        // La re-aceptación con la OT en curso toma la ejecución actual.
        $this->actingAsAdmin();
        $this->postJson("/api/budgets/{$budget->id}/client-acceptances", ['channel' => 'whatsapp'])
            ->assertCreated()->assertJsonPath('data.owner_amount', '180000.00');
        $this->getJson("/api/work-orders/{$workOrder['id']}")->assertOk()->assertJsonPath('data.owner_amount_warning', null);

        // El deducible sube: advertencia hasta que el dueño lo acepta.
        $claim = Claim::where('reception_id', $budget->reception_id)->sole();
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_amount' => 500000])->assertOk();
        $this->getJson("/api/work-orders/{$workOrder['id']}")
            ->assertOk()
            ->assertJsonPath('data.owner_amount_warning.amount_exceeded', false)
            ->assertJsonPath('data.owner_amount_warning.deductible_increased', true)
            ->assertJsonPath('data.owner_amount_warning.current_deductible', '500000.00');
        $this->postJson("/api/budgets/{$budget->id}/client-acceptances", ['channel' => 'whatsapp'])
            ->assertCreated()->assertJsonPath('data.deductible_amount', '500000.00');
        $this->getJson("/api/work-orders/{$workOrder['id']}")->assertOk()->assertJsonPath('data.owner_amount_warning', null);

        // Un extra en la OT con siniestro avisa que queda a cargo del dueño (P5); aprobado, suma al tope.
        $extraPart = $this->createPartViaApi();
        $this->entry($extraPart['id'], 5, 1000, ['type' => 'initial'])->assertCreated();
        $extra = $this->postJson($this->partsUrl($workOrder), ['part_id' => $extraPart['id'], 'quantity' => 2, 'unit_price' => 5000])
            ->assertCreated()
            ->assertJsonPath('meta.warnings.0', OwnerShareService::EXTRA_WARNING)
            ->json('data');
        $this->postJson($this->partsUrl($workOrder, $extra['id'], 'approve'), ['channel' => 'phone'])->assertOk();
        $this->getJson("/api/work-orders/{$workOrder['id']}")->assertOk()->assertJsonPath('data.owner_amount_warning', null);

        // Con la OT completada ya no hay re-aceptación.
        $this->postJson($this->partsUrl($workOrder, $extra['id'], 'consume'), ['quantity_used' => 2])->assertOk();
        $this->completeAllItems($workOrder);
        $this->postJson("/api/work-orders/{$workOrder['id']}/complete")->assertOk();
        $this->postJson("/api/budgets/{$budget->id}/client-acceptances", ['channel' => 'whatsapp'])
            ->assertStatus(422)->assertJsonPath('error_code', 'BUDGET_NOT_REACCEPTABLE');
    }

    public function test_the_workshop_absorption_is_granted_on_a_claim_work_order(): void
    {
        $this->actingAsAdmin();
        $budget = $this->insuredBudget();
        $this->authorize($budget);
        $this->postJson("/api/budgets/{$budget->id}/approve")->assertOk();
        $workOrder = $this->createWorkOrderFor($budget)->assertCreated()->json('data');

        $this->grant(['type' => 'workshop_absorption', 'subject_id' => $workOrder['id']])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->grant(['type' => 'workshop_absorption', 'subject_id' => $workOrder['id'], 'amount' => 50000])
            ->assertCreated()
            ->assertJsonPath('data.type.label', 'Absorción del taller')
            ->assertJsonPath('data.subject.type', 'work_order')
            ->assertJsonPath('data.subject.code', $workOrder['code'])
            ->assertJsonPath('data.amount', '50000.00');

        // En una OT particular no hay reparto: no se otorga.
        $particular = $this->createBudgetWithItems([['type' => 'labor', 'description' => 'Alineación', 'quantity' => 1, 'unit_price' => 150000]]);
        $this->approveBudget($particular);
        $particularOrder = $this->createWorkOrder($particular);
        $this->grant(['type' => 'workshop_absorption', 'subject_id' => $particularOrder['id'], 'amount' => 1000])
            ->assertStatus(422)->assertJsonPath('error_code', 'INTERNAL_EXCEPTION_SUBJECT_INVALID');
    }

    public function test_the_particular_flow_does_not_change(): void
    {
        $this->actingAsAdmin();
        $budgetId = $this->createBudgetWithItems([['type' => 'labor', 'description' => 'Alineación', 'quantity' => 1, 'unit_price' => 150000]]);
        $this->postJson("/api/budgets/{$budgetId}/send")->assertOk();

        $this->postJson("/api/budgets/{$budgetId}/client-acceptances", ['channel' => 'phone'])
            ->assertStatus(422)->assertJsonPath('error_code', 'BUDGET_NOT_REACCEPTABLE');

        $this->postJson("/api/budgets/{$budgetId}/approve")
            ->assertOk()
            ->assertJsonPath('data.client_acceptances.0.owner_amount', '150000.00')
            ->assertJsonPath('data.client_acceptances.0.insurer_authorization_id', null)
            ->assertJsonPath('data.client_acceptances.0.deductible_amount', null);

        $exception = InternalException::create([
            'type' => InternalExceptionType::START_WITHOUT_AUTHORIZATION,
            'subject_type' => 'budget',
            'subject_id' => $budgetId,
            'reason' => 'Creada a mano para la prueba.',
            'granted_by' => User::firstOrFail()->id,
            'granted_at' => now(),
        ]);
        $this->postJson('/api/work-orders', ['budget_id' => $budgetId, 'mechanic_id' => $this->createMechanic()->id, 'internal_exception_id' => $exception->id])
            ->assertStatus(422)->assertJsonPath('error_code', 'INTERNAL_EXCEPTION_NOT_APPLICABLE');

        $workOrder = $this->createWorkOrder($budgetId);
        $this->assertFalse($workOrder['has_claim']);
        $this->assertNull($workOrder['owner_amount_warning']);

        $part = $this->createPartViaApi();
        $this->entry($part['id'], 5, 1000, ['type' => 'initial'])->assertCreated();
        $this->postJson($this->partsUrl($workOrder), ['part_id' => $part['id'], 'quantity' => 1])
            ->assertCreated()->assertJsonPath('meta', null);
    }

    public function test_permissions_by_role(): void
    {
        $this->actingAsAdmin();
        $budget = $this->insuredBudget();
        $this->postJson("/api/budgets/{$budget->id}/approve")->assertOk();
        $body = ['type' => 'start_without_authorization', 'subject_id' => $budget->id];

        $this->actingAsRole('recepcionista');
        $this->grant($body)->assertForbidden();
        $this->getJson('/api/internal-exceptions')->assertForbidden();
        $this->postJson("/api/budgets/{$budget->id}/client-acceptances", ['channel' => 'phone'])->assertCreated();

        $this->actingAsRole('cajero');
        $this->grant($body)->assertForbidden();
        $this->getJson('/api/internal-exceptions')->assertOk();

        $this->actingAsRole('jefe-taller');
        $this->postJson("/api/budgets/{$budget->id}/client-acceptances", ['channel' => 'phone'])->assertForbidden();
        $this->getJson("/api/budgets/{$budget->id}/client-acceptances")->assertOk();
        $id = $this->grant($body)->assertCreated()->json('data.id');
        $this->getJson("/api/internal-exceptions/{$id}")->assertOk();

        foreach (['deposito', 'mechanic'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/internal-exceptions')->assertForbidden();
            $this->grant($body)->assertForbidden();
        }
    }
}

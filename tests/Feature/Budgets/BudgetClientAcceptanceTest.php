<?php

namespace Tests\Feature\Budgets;

use App\Exceptions\BudgetException;
use App\Models\AuditLog;
use App\Models\BudgetClientAcceptance;
use Tests\Feature\WorkOrderParts\WorkOrderPartsTestCase;

/**
 * Respuesta del cliente a un presupuesto (E5): cada aprobación o rechazo deja una
 * fila inmutable con quién lo registró, cuándo, por qué medio y por qué importe.
 */
class BudgetClientAcceptanceTest extends WorkOrderPartsTestCase
{
    private function sentBudget(): int
    {
        $budgetId = $this->createBudgetWithItems([
            ['type' => 'labor', 'description' => 'Chapería', 'quantity' => 1, 'unit_price' => 100000],
        ]);

        $this->postJson("/api/budgets/{$budgetId}/send")->assertOk();

        return $budgetId;
    }

    public function test_approving_without_body_works_as_before_and_records_the_acceptance(): void
    {
        $admin = $this->actingAsAdmin();
        $budgetId = $this->sentBudget();

        $this->postJson("/api/budgets/{$budgetId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonCount(1, 'data.client_acceptances')
            ->assertJsonPath('data.client_acceptances.0.kind.value', 'approval')
            ->assertJsonPath('data.client_acceptances.0.kind.label', 'Aprobación')
            ->assertJsonPath('data.client_acceptances.0.decided_by.id', $admin->id)
            ->assertJsonPath('data.client_acceptances.0.channel', null)
            ->assertJsonPath('data.client_acceptances.0.contact', null)
            ->assertJsonPath('data.client_acceptances.0.owner_amount', '100000.00');
    }

    public function test_the_decision_details_are_saved(): void
    {
        $this->actingAsAdmin();
        $budgetId = $this->sentBudget();

        $this->postJson("/api/budgets/{$budgetId}/approve", [
            'channel' => 'whatsapp',
            'contact' => '+595981123456',
            'notes' => 'Aprobó por mensaje.',
        ])
            ->assertOk()
            ->assertJsonPath('data.client_acceptances.0.channel.value', 'whatsapp')
            ->assertJsonPath('data.client_acceptances.0.channel.label', 'WhatsApp')
            ->assertJsonPath('data.client_acceptances.0.contact', '+595981123456')
            ->assertJsonPath('data.client_acceptances.0.notes', 'Aprobó por mensaje.');

        $this->getJson("/api/budgets/{$budgetId}")->assertJsonCount(1, 'data.client_acceptances');
    }

    public function test_an_invalid_channel_is_rejected(): void
    {
        $this->actingAsAdmin();
        $budgetId = $this->sentBudget();

        $this->postJson("/api/budgets/{$budgetId}/approve", ['channel' => 'fax'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('channel');

        $this->postJson("/api/budgets/{$budgetId}/reject", ['notes' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes');

        $this->assertSame(0, BudgetClientAcceptance::count());
    }

    public function test_reopening_keeps_the_history(): void
    {
        $this->actingAsAdmin();
        $budgetId = $this->sentBudget();

        $this->postJson("/api/budgets/{$budgetId}/reject", ['channel' => 'phone', 'notes' => 'Le pareció caro.'])
            ->assertOk()
            ->assertJsonPath('data.client_acceptances.0.kind.value', 'rejection');

        $this->postJson("/api/budgets/{$budgetId}/reopen")->assertOk();
        $this->patchJson("/api/budgets/{$budgetId}/items/" . BudgetClientAcceptance::sole()->budget->items()->sole()->id, [
            'unit_price' => 80000,
        ])->assertOk();
        $this->postJson("/api/budgets/{$budgetId}/send")->assertOk();

        $this->travel(1)->minutes();

        $this->postJson("/api/budgets/{$budgetId}/approve", ['channel' => 'in_person'])
            ->assertOk()
            ->assertJsonCount(2, 'data.client_acceptances')
            ->assertJsonPath('data.client_acceptances.0.kind.value', 'rejection')
            ->assertJsonPath('data.client_acceptances.0.owner_amount', '100000.00')
            ->assertJsonPath('data.client_acceptances.0.notes', 'Le pareció caro.')
            ->assertJsonPath('data.client_acceptances.1.kind.value', 'approval')
            ->assertJsonPath('data.client_acceptances.1.owner_amount', '80000.00');
    }

    public function test_acceptances_are_immutable(): void
    {
        $this->actingAsAdmin();
        $budgetId = $this->sentBudget();
        $this->postJson("/api/budgets/{$budgetId}/approve")->assertOk();

        $acceptance = BudgetClientAcceptance::sole();

        foreach ([fn () => $acceptance->update(['notes' => 'Cambiado']), fn () => $acceptance->delete()] as $change) {
            try {
                $change();
                $this->fail('Debía rechazar el cambio.');
            } catch (BudgetException $e) {
                $this->assertSame(409, $e->getStatus());
                $this->assertSame('BUDGET_CLIENT_ACCEPTANCE_IMMUTABLE', $e->getErrorCode());
            }
        }

        $this->assertNull($acceptance->refresh()->notes);
    }

    public function test_budget_and_acceptances_are_audited(): void
    {
        $this->actingAsAdmin();
        $budgetId = $this->sentBudget();
        $this->postJson("/api/budgets/{$budgetId}/approve")->assertOk();

        $this->assertTrue(AuditLog::where('auditable_type', 'budget')->where('auditable_id', $budgetId)->where('event', 'updated')->exists());
        $this->assertTrue(AuditLog::where('auditable_type', 'budget_client_acceptance')->where('event', 'created')->exists());
    }
}

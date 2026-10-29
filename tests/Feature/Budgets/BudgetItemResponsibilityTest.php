<?php

namespace Tests\Feature\Budgets;

use App\Models\BudgetItem;
use App\Models\Reception;
use App\Models\User;
use App\Services\BudgetService;
use Tests\Feature\Claims\ClaimTestCase;

/**
 * Responsabilidad por línea del presupuesto (M5): el dueño por defecto, la
 * aseguradora solo con un siniestro abierto, y vuelta al dueño en los borradores
 * cuando se cancela el siniestro.
 */
class BudgetItemResponsibilityTest extends ClaimTestCase
{
    private function budgetFor(Reception $reception): int
    {
        return app(BudgetService::class)->create(['reception_id' => $reception->id], User::firstOrFail()->id)->id;
    }

    private function line(array $overrides = []): array
    {
        return ['type' => 'labor', 'description' => 'Chapería de puerta', 'quantity' => 1, 'unit_price' => 500000, ...$overrides];
    }

    private function claimOn(Reception $reception): int
    {
        return $this->postJson('/api/claims', $this->claimBody($reception->id, $this->insurer()))->assertCreated()->json('data.id');
    }

    public function test_lines_belong_to_the_owner_by_default(): void
    {
        $this->actingAsAdmin();
        $reception = $this->openReception();
        $this->claimOn($reception);
        $budgetId = $this->budgetFor($reception);

        $this->postJson("/api/budgets/{$budgetId}/items", $this->line())
            ->assertCreated()
            ->assertJsonPath('data.responsibility.value', 'owner')
            ->assertJsonPath('data.responsibility.label', 'Dueño');
    }

    public function test_insurer_lines_require_an_open_claim(): void
    {
        $this->actingAsAdmin();
        $reception = $this->openReception();
        $budgetId = $this->budgetFor($reception);

        $this->postJson("/api/budgets/{$budgetId}/items", $this->line(['responsibility' => 'insurer']))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BUDGET_ITEM_INSURER_WITHOUT_CLAIM');
        $this->postJson("/api/budgets/{$budgetId}/items", $this->line(['responsibility' => 'nadie']))
            ->assertStatus(422)->assertJsonValidationErrors('responsibility');
        $this->assertSame(0, BudgetItem::count());

        $this->claimOn($reception);

        $itemId = $this->postJson("/api/budgets/{$budgetId}/items", $this->line(['responsibility' => 'insurer']))
            ->assertCreated()
            ->assertJsonPath('data.responsibility.value', 'insurer')
            ->json('data.id');

        $this->patchJson("/api/budgets/{$budgetId}/items/{$itemId}", ['responsibility' => 'owner'])
            ->assertOk()->assertJsonPath('data.responsibility.value', 'owner');
        $this->getJson("/api/budgets/{$budgetId}")->assertOk()->assertJsonPath('data.items.0.responsibility.value', 'owner');
    }

    public function test_only_drafts_change_their_responsibility(): void
    {
        $this->actingAsAdmin();
        $reception = $this->openReception();
        $this->claimOn($reception);
        $budgetId = $this->budgetFor($reception);
        $itemId = $this->postJson("/api/budgets/{$budgetId}/items", $this->line(['responsibility' => 'insurer']))->json('data.id');

        $this->postJson("/api/budgets/{$budgetId}/send")->assertOk();

        $this->patchJson("/api/budgets/{$budgetId}/items/{$itemId}", ['responsibility' => 'owner'])
            ->assertStatus(422)->assertJsonPath('error_code', 'BUDGET_NOT_EDITABLE');
    }

    public function test_cancelling_the_claim_returns_draft_lines_to_the_owner(): void
    {
        $this->actingAsAdmin();
        $reception = $this->openReception();
        $claimId = $this->claimOn($reception);

        $draft = $this->budgetFor($reception);
        $draftItem = $this->postJson("/api/budgets/{$draft}/items", $this->line(['responsibility' => 'insurer']))->json('data.id');

        $sent = $this->budgetFor($reception);
        $sentItem = $this->postJson("/api/budgets/{$sent}/items", $this->line(['responsibility' => 'insurer']))->json('data.id');
        $this->postJson("/api/budgets/{$sent}/send")->assertOk();

        $this->postJson("/api/claims/{$claimId}/cancel", ['reason' => 'La aseguradora rechazó la cobertura.'])->assertOk();

        $this->assertSame('owner', BudgetItem::findOrFail($draftItem)->responsibility->value);
        // El enviado no se toca: se rechaza, se reabre y el cliente vuelve a aprobar.
        $this->assertSame('insurer', BudgetItem::findOrFail($sentItem)->responsibility->value);

        // Sin siniestro abierto ya no se cargan líneas a la aseguradora.
        $this->postJson("/api/budgets/{$draft}/items", $this->line(['responsibility' => 'insurer']))
            ->assertStatus(422)->assertJsonPath('error_code', 'BUDGET_ITEM_INSURER_WITHOUT_CLAIM');
    }
}

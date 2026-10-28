<?php

namespace Tests\Feature\Budgets;

use App\Models\Budget;
use App\Models\BudgetItem;
use Tests\Feature\WorkOrderParts\WorkOrderPartsTestCase;

/**
 * Importes del presupuesto en decimal(14,2) (E4). SQLite no aplica la precisión
 * de las columnas: estos tests cubren el cálculo y los topes; la ampliación en
 * sí se verificó en MySQL (docs/handoff.md, 2.56).
 */
class BudgetAmountRangeTest extends WorkOrderPartsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
    }

    private function labor(string|int|float $unitPrice, string|int|float $quantity = 1): array
    {
        return ['type' => 'labor', 'description' => 'Chapería completa', 'quantity' => $quantity, 'unit_price' => $unitPrice];
    }

    public function test_a_budget_above_the_old_limit_is_saved_and_recalculated(): void
    {
        $budgetId = $this->createBudgetWithItems([$this->labor(150000000)]);

        $budget = Budget::findOrFail($budgetId);
        $this->assertSame(0, bccomp('150000000.00', (string) $budget->subtotal, 2));
        $this->assertSame(0, bccomp('15000000.00', (string) $budget->tax, 2));
        $this->assertSame(0, bccomp('165000000.00', (string) $budget->total, 2));

        $this->getJson("/api/budgets/{$budgetId}")->assertOk();
    }

    public function test_unit_price_and_quantity_have_a_maximum(): void
    {
        $budgetId = $this->createBudgetWithItems([]);

        $this->postJson("/api/budgets/{$budgetId}/items", $this->labor('1000000000000'))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('unit_price');

        $this->postJson("/api/budgets/{$budgetId}/items", $this->labor(1000, '100000000'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
    }

    public function test_an_item_total_out_of_range_is_rejected_without_saving(): void
    {
        $budgetId = $this->createBudgetWithItems([]);

        $this->postJson("/api/budgets/{$budgetId}/items", $this->labor('999999999999.99', 2))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BUDGET_AMOUNT_OUT_OF_RANGE');

        $this->assertSame(0, BudgetItem::where('budget_id', $budgetId)->count());
    }

    public function test_a_budget_total_out_of_range_rolls_back_the_item(): void
    {
        // El ítem entra (909.090.909.091), pero con el IVA el total pasa el máximo.
        $budgetId = $this->createBudgetWithItems([]);

        $this->postJson("/api/budgets/{$budgetId}/items", $this->labor('909090909091'))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BUDGET_AMOUNT_OUT_OF_RANGE');

        $this->assertSame(0, BudgetItem::where('budget_id', $budgetId)->count());
        $this->assertSame(0, bccomp('0', (string) Budget::findOrFail($budgetId)->total, 2));
    }

    public function test_an_update_out_of_range_leaves_the_item_unchanged(): void
    {
        $budgetId = $this->createBudgetWithItems([$this->labor(1000000)]);
        $item = BudgetItem::where('budget_id', $budgetId)->sole();

        $this->patchJson("/api/budgets/{$budgetId}/items/{$item->id}", ['unit_price' => '909090909091'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BUDGET_AMOUNT_OUT_OF_RANGE');

        $this->assertSame(0, bccomp('1000000', (string) $item->refresh()->unit_price, 2));
        $this->assertSame(0, bccomp('1100000', (string) Budget::findOrFail($budgetId)->total, 2));
    }
}

<?php

namespace Tests\Feature\WorkOrderParts;

use App\Models\Brand;
use App\Models\Client;
use App\Models\Mechanic;
use App\Models\Reception;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\BudgetService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Inventory\InventoryTestCase;

abstract class WorkOrderPartsTestCase extends InventoryTestCase
{
    /**
     * Crea un presupuesto en borrador con sus ítems cargados por API.
     *
     * @param array<int, array<string, mixed>> $items
     */
    protected function createBudgetWithItems(array $items): int
    {
        $user = User::first();

        $brand = Brand::create(['name' => 'Marca ' . Str::random(6)]);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Modelo ' . Str::random(6)]);
        $client = Client::create([
            'document_number' => 'QA-' . random_int(100000, 999999),
            'first_name' => 'Ana',
            'last_name' => 'Gomez',
            'phone' => '0981' . random_int(100000, 999999),
            'email' => 'qa-' . random_int(100000, 999999) . '@example.com',
        ]);
        $vehicle = Vehicle::create([
            'client_id' => $client->id,
            'brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'plate' => Str::upper(Str::random(7)),
        ]);
        $reception = Reception::create([
            'client_id' => $client->id,
            'vehicle_id' => $vehicle->id,
            'reception_date' => now()->toDateString(),
        ]);

        $budget = app(BudgetService::class)->create(['reception_id' => $reception->id], $user->id);

        foreach ($items as $item) {
            $this->postJson("/api/budgets/{$budget->id}/items", $item)->assertCreated();
        }

        return $budget->id;
    }

    protected function approveBudget(int $budgetId): void
    {
        $this->postJson("/api/budgets/{$budgetId}/send")->assertOk();
        $this->postJson("/api/budgets/{$budgetId}/approve")->assertOk();
    }

    protected function createMechanic(array $attributes = []): Mechanic
    {
        $user = User::create([
            'name' => 'mec_' . Str::random(6),
            'first_name' => 'Juan',
            'last_name' => 'Mecanico',
            'email' => Str::random(10) . '@test.local',
            'password' => bcrypt('password'),
        ]);

        $user->assignRole('mechanic');

        return Mechanic::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-' . Str::upper(Str::random(5)),
            ...$attributes,
        ]);
    }

    protected function createWorkOrder(int $budgetId): array
    {
        return $this->postJson('/api/work-orders', [
            'budget_id' => $budgetId,
            'mechanic_id' => $this->createMechanic()->id,
        ])->assertCreated()->json('data');
    }

    /**
     * Presupuesto aprobado con un repuesto vinculado y mano de obra → OT.
     */
    protected function workOrderWithPart(array $part, float|int $quantity, float|int $unitPrice = 150): array
    {
        $budgetId = $this->createBudgetWithItems([
            [
                'type' => 'part',
                'part_id' => $part['id'],
                'description' => 'Repuesto presupuestado',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ],
            [
                'type' => 'labor',
                'description' => 'Mano de obra',
                'quantity' => 1,
                'unit_price' => 500,
            ],
        ]);

        $this->approveBudget($budgetId);

        return $this->createWorkOrder($budgetId);
    }

    protected function partsUrl(array $workOrder, ?int $lineId = null, string $action = ''): string
    {
        $url = "/api/work-orders/{$workOrder['id']}/parts";

        if ($lineId !== null) {
            $url .= "/{$lineId}";
        }

        return $action !== '' ? "{$url}/{$action}" : $url;
    }

    /**
     * Agrega un repuesto extra y registra la aprobación del cliente.
     */
    protected function addApprovedExtra(array $workOrder, array $data): array
    {
        $line = $this->postJson($this->partsUrl($workOrder), $data)->assertCreated()->json('data');

        return $this->postJson($this->partsUrl($workOrder, $line['id'], 'approve'), ['channel' => 'phone'])
            ->assertOk()
            ->json('data');
    }

    protected function startWorkOrder(array $workOrder): TestResponse
    {
        return $this->postJson("/api/work-orders/{$workOrder['id']}/start")->assertOk();
    }

    /**
     * Inicia y completa todos los ítems de la OT (la OT debe estar en progreso).
     */
    protected function completeAllItems(array $workOrder): void
    {
        foreach ($workOrder['items'] as $item) {
            $this->postJson("/api/work-orders/{$workOrder['id']}/items/{$item['id']}/start")->assertOk();
            $this->postJson("/api/work-orders/{$workOrder['id']}/items/{$item['id']}/complete")->assertOk();
        }
    }
}

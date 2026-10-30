<?php

namespace Tests\Feature\Maintenance;

use App\Models\Brand;
use App\Models\Client;
use App\Models\Reception;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\BudgetService;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\WorkOrderParts\WorkOrderPartsTestCase;

class MaintenanceTest extends WorkOrderPartsTestCase
{
    private Client $client;
    private Vehicle $vehicle;
    private array $oilService;
    private array $oilPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();

        $category = ServiceCategory::create(['code' => 'mechanical', 'name' => 'Mecánica']);
        $this->oilService = $this->postJson('/api/services', [
            'service_category_id' => $category->id,
            'name' => 'Cambio de aceite y filtro',
            'standard_price' => 80000,
        ])->assertCreated()->json('data');

        $this->oilPlan = $this->postJson('/api/maintenance-plans', [
            'name' => 'Cambio de aceite',
            'service_id' => $this->oilService['id'],
            'interval_km' => 5000,
            'interval_days' => 180,
        ])->assertCreated()->json('data');

        $this->client = Client::create(['document_number' => 'QA-7001', 'first_name' => 'Ana', 'last_name' => 'Gomez', 'phone' => '+595981000777', 'email' => 'qa-7001@example.com']);
        $brand = Brand::create(['name' => 'Toyota']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Hilux']);
        $this->vehicle = Vehicle::create(['client_id' => $this->client->id, 'brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'plate' => 'QAM123']);
    }

    private function reception(int $daysAgo, int $mileage): Reception
    {
        return Reception::create([
            'client_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'reception_date' => now()->subDays($daysAgo)->toDateString(),
            'mileage' => $mileage,
        ]);
    }

    private function maintenances(): array
    {
        return collect($this->getJson("/api/vehicles/{$this->vehicle->id}/maintenances")->assertOk()->json('data.maintenances'))
            ->keyBy('plan')
            ->all();
    }

    private function record(array $overrides = [])
    {
        return $this->postJson("/api/vehicles/{$this->vehicle->id}/maintenances", [
            'maintenance_plan_id' => $this->oilPlan['id'],
            'done_at' => now()->toDateString(),
            'mileage' => 15000,
            ...$overrides,
        ]);
    }

    public function test_crud_of_plans(): void
    {
        $this->assertSame($this->oilService['id'], $this->oilPlan['service']['id']);

        $this->postJson('/api/maintenance-plans', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'interval_km', 'interval_days']);
        $this->postJson('/api/maintenance-plans', ['name' => 'Cambio de aceite', 'interval_km' => 1000])->assertJsonValidationErrors(['name']);
        $this->postJson('/api/maintenance-plans', ['name' => 'Frenos', 'interval_days' => 5000])->assertJsonValidationErrors(['interval_days']);
        $this->postJson('/api/maintenance-plans', ['name' => 'Frenos', 'interval_km' => 0])->assertJsonValidationErrors(['interval_km']);

        $brakes = $this->postJson('/api/maintenance-plans', ['name' => 'Revision de frenos', 'interval_days' => 365])
            ->assertCreated()
            ->assertJsonPath('data.interval_km', null)
            ->assertJsonPath('data.is_active', true)
            ->json('data');

        $this->getJson('/api/maintenance-plans')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/maintenance-plans/{$brakes['id']}")->assertOk();

        $this->patchJson("/api/maintenance-plans/{$brakes['id']}", ['interval_days' => null])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'MAINTENANCE_INTERVAL_REQUIRED');

        $this->patchJson("/api/maintenance-plans/{$brakes['id']}", ['interval_km' => 20000, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.interval_km', 20000)
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/maintenance-plans?is_active=1')->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/maintenance-plans/{$brakes['id']}")->assertOk();

        // Un plan con seguimiento no se elimina.
        $this->record()->assertCreated();
        $this->deleteJson("/api/maintenance-plans/{$this->oilPlan['id']}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'MAINTENANCE_PLAN_IN_USE');
    }

    public function test_completed_work_order_registers_the_maintenance(): void
    {
        $reception = $this->reception(0, 42000);

        $budget = app(BudgetService::class)->create(['reception_id' => $reception->id], User::first()->id);
        $this->postJson("/api/budgets/{$budget->id}/items", ['type' => 'service', 'service_id' => $this->oilService['id'], 'description' => 'Cambio de aceite', 'quantity' => 1])->assertCreated();
        $this->postJson("/api/budgets/{$budget->id}/items", ['type' => 'labor', 'description' => 'Revision', 'quantity' => 1, 'unit_price' => 1000])->assertCreated();
        $this->approveBudget($budget->id);

        $workOrder = $this->createWorkOrder($budget->id);

        // Antes de completar la OT no hay registro.
        $this->assertSame('unknown', $this->maintenances()['Cambio de aceite']['status']['value']);

        $this->startWorkOrder($workOrder);
        $this->completeAllItems($workOrder);
        $this->postJson("/api/work-orders/{$workOrder['id']}/complete")->assertOk();

        $oil = $this->maintenances()['Cambio de aceite'];

        $this->assertSame(now()->toDateString(), $oil['last_done_at']);
        $this->assertSame(42000, $oil['last_done_mileage']);
        $this->assertSame($workOrder['id'], $oil['work_order_id']);
        $this->assertSame(47000, $oil['next_due_mileage']);
        $this->assertSame(now()->addDays(180)->toDateString(), $oil['next_due_at']);
        $this->assertSame('ok', $oil['status']['value']);
    }

    public function test_manual_record_rules(): void
    {
        $this->record(['done_at' => now()->subDays(10)->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.next_due_mileage', 20000);

        $this->record(['done_at' => now()->subDays(20)->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'MAINTENANCE_DONE_OUT_OF_ORDER');

        $this->record(['mileage' => 14000])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'MAINTENANCE_MILEAGE_BELOW_LAST');

        $this->record(['done_at' => now()->addDay()->toDateString()])->assertJsonValidationErrors(['done_at']);
        $this->record(['maintenance_plan_id' => 999999])->assertJsonValidationErrors(['maintenance_plan_id']);

        $this->patchJson("/api/maintenance-plans/{$this->oilPlan['id']}", ['is_active' => false])->assertOk();
        $this->record()->assertStatus(422)->assertJsonPath('error_code', 'MAINTENANCE_PLAN_INACTIVE');
    }

    public function test_status_uses_the_estimated_mileage(): void
    {
        // 10.000 km hace 100 días y 15.000 hace 50: 100 km/día → hoy ~20.000 km.
        $this->reception(100, 10000);
        $this->reception(50, 15000);

        $this->record(['done_at' => now()->subDays(50)->toDateString(), 'mileage' => 15000])->assertCreated();

        $data = $this->getJson("/api/vehicles/{$this->vehicle->id}/maintenances")->assertOk()->json('data');

        $this->assertSame(20000, $data['current_mileage']['mileage']);
        $this->assertTrue($data['current_mileage']['estimated']);
        $this->assertEquals(100, $data['current_mileage']['daily_average']);

        $oil = collect($data['maintenances'])->firstWhere('plan', 'Cambio de aceite');
        $this->assertSame('overdue', $oil['status']['value']);
        $this->assertSame(0, $oil['remaining_km']);
        $this->assertSame(130, $oil['remaining_days']);

        // Intervalo propio del vehículo: aceite sintético cada 10.000 km.
        $this->patchJson("/api/vehicles/{$this->vehicle->id}/maintenances/{$this->oilPlan['id']}", ['interval_km' => 10000])
            ->assertOk()
            ->assertJsonPath('data.is_custom_interval', true)
            ->assertJsonPath('data.next_due_mileage', 25000)
            ->assertJsonPath('data.status.value', 'ok');

        // Faltan 800 km (menos de 1.000): por vencer.
        $this->patchJson("/api/vehicles/{$this->vehicle->id}/maintenances/{$this->oilPlan['id']}", ['interval_km' => 5800])
            ->assertOk()
            ->assertJsonPath('data.remaining_km', 800)
            ->assertJsonPath('data.status.value', 'due_soon');

        // null vuelve al intervalo del plan.
        $this->patchJson("/api/vehicles/{$this->vehicle->id}/maintenances/{$this->oilPlan['id']}", ['interval_km' => null])
            ->assertOk()
            ->assertJsonPath('data.is_custom_interval', false)
            ->assertJsonPath('data.next_due_mileage', 20000);
    }

    public function test_date_based_plan_and_plan_interval_changes(): void
    {
        $plan = $this->postJson('/api/maintenance-plans', ['name' => 'Revision de frenos', 'interval_days' => 30])->json('data');

        $this->record(['maintenance_plan_id' => $plan['id'], 'done_at' => now()->subDays(35)->toDateString(), 'mileage' => null])->assertCreated();

        $brakes = $this->maintenances()['Revision de frenos'];
        $this->assertSame('overdue', $brakes['status']['value']);
        $this->assertSame(-5, $brakes['remaining_days']);
        $this->assertNull($brakes['remaining_km']);

        // Cambiar el intervalo del plan recalcula el vencimiento.
        $this->patchJson("/api/maintenance-plans/{$plan['id']}", ['interval_days' => 60])->assertOk();

        $brakes = $this->maintenances()['Revision de frenos'];
        $this->assertSame(25, $brakes['remaining_days']);
        $this->assertSame('due_soon', $brakes['status']['value']);
    }

    public function test_alerts(): void
    {
        $this->reception(100, 10000);
        $this->reception(50, 15000);
        $this->record(['done_at' => now()->subDays(50)->toDateString(), 'mileage' => 15000])->assertCreated();

        $brakes = $this->postJson('/api/maintenance-plans', ['name' => 'Revision de frenos', 'interval_days' => 60])->json('data');
        $this->record(['maintenance_plan_id' => $brakes['id'], 'done_at' => now()->subDays(40)->toDateString(), 'mileage' => null])->assertCreated();

        // Plan sin registro: no genera alerta.
        $this->postJson('/api/maintenance-plans', ['name' => 'Alineacion', 'interval_km' => 10000])->assertCreated();

        $response = $this->getJson('/api/maintenance/alerts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.counts.overdue', 1)
            ->assertJsonPath('meta.counts.due_soon', 1)
            // Vencidos primero.
            ->assertJsonPath('data.0.plan', 'Cambio de aceite')
            ->assertJsonPath('data.0.status.value', 'overdue')
            ->assertJsonPath('data.0.vehicle.plate', 'QAM123')
            ->assertJsonPath('data.0.client.phone', '+595981000777');

        $this->getJson('/api/maintenance/alerts?status=due_soon')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.plan', 'Revision de frenos');

        $this->getJson('/api/maintenance/alerts?status=ok')->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');

        // Un plan desactivado deja de alertar.
        $this->patchJson("/api/maintenance-plans/{$this->oilPlan['id']}", ['is_active' => false])->assertOk();
        $this->getJson('/api/maintenance/alerts')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_permissions(): void
    {
        $this->actingAsMechanic();
        $this->getJson("/api/vehicles/{$this->vehicle->id}/maintenances")->assertOk();
        $this->getJson('/api/maintenance-plans')->assertOk();
        $this->record()->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->getJson('/api/maintenance/alerts')->assertForbidden();
        $this->postJson('/api/maintenance-plans', ['name' => 'X plan', 'interval_km' => 1000])->assertForbidden();

        $this->actingAsRole('recepcionista');
        $this->getJson('/api/maintenance/alerts')->assertOk();
        $this->record()->assertCreated();
        $this->patchJson("/api/vehicles/{$this->vehicle->id}/maintenances/{$this->oilPlan['id']}", ['interval_km' => 10000])->assertForbidden();

        $this->actingAsRole('jefe-taller');
        $this->patchJson("/api/vehicles/{$this->vehicle->id}/maintenances/{$this->oilPlan['id']}", ['interval_km' => 10000])->assertOk();

        $this->actingAsRole('cajero');
        $this->getJson('/api/maintenance/alerts')->assertForbidden();

        Sanctum::actingAs(User::create(['name' => 'sin_rol', 'first_name' => 'Sin', 'last_name' => 'Rol', 'email' => 'sin@test.local', 'password' => bcrypt('x')]));
        $this->getJson("/api/vehicles/{$this->vehicle->id}/maintenances")->assertForbidden();
    }
}

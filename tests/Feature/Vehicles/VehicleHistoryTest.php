<?php

namespace Tests\Feature\Vehicles;

use App\Models\Brand;
use App\Models\Client;
use App\Models\Reception;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\BudgetService;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Invoices\InvoiceTestCase;

class VehicleHistoryTest extends InvoiceTestCase
{
    private Vehicle $vehicle;
    private array $firstWorkOrder;
    private array $secondWorkOrder;

    /**
     * Vehículo con dos visitas:
     *  1. Hace dos meses (40.000 km): aceite 1,7 L + mano de obra 200.000, facturada,
     *     pagada y entregada con 40.100 km.
     *  2. Hoy (52.000 km): OT pendiente.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
        $this->createStamp()->assertCreated();

        $client = Client::create(['document_number' => 'QA-9001', 'first_name' => 'Ana', 'last_name' => 'Gomez', 'phone' => '+595981000999', 'email' => 'qa-9001@example.com']);
        $brand = Brand::create(['name' => 'Toyota']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Hilux']);
        $this->vehicle = Vehicle::create(['client_id' => $client->id, 'brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'plate' => 'QAH123']);

        $oil = $this->createPartViaApi(null, ['unit' => 'liter']);
        $this->entry($oil['id'], 10, 100, ['type' => 'initial'])->assertCreated();

        // Primera visita.
        $first = $this->reception($client, now()->subMonths(2)->toDateString(), 40000);
        $this->firstWorkOrder = $this->workOrderFor($first, [
            ['type' => 'part', 'part_id' => $oil['id'], 'description' => 'Aceite', 'quantity' => 2, 'unit_price' => 150],
            ['type' => 'labor', 'description' => 'Cambio de aceite', 'quantity' => 1, 'unit_price' => 200000],
        ]);

        $this->startWorkOrder($this->firstWorkOrder);
        $this->postJson($this->partsUrl($this->firstWorkOrder, $this->firstWorkOrder['parts'][0]['id'], 'consume'), ['quantity_used' => 1.7])->assertOk();
        $this->completeAllItems($this->firstWorkOrder);
        $this->postJson("/api/work-orders/{$this->firstWorkOrder['id']}/complete")->assertOk();

        $invoice = $this->generateInvoice($this->firstWorkOrder);
        $this->patchJson("/api/invoices/{$invoice['id']}", ['customer_tax_id' => '80012345-6'])->assertOk();
        $invoice = $this->postJson("/api/invoices/{$invoice['id']}/issue")->assertOk()->json('data');
        $this->postJson("/api/invoices/{$invoice['id']}/payments", ['amount' => (int) $invoice['totals']['total'], 'method' => 'cash'])->assertCreated();

        $this->postJson("/api/receptions/{$first->id}/delivery", [
            'final_mileage' => 40100,
            'received_by_name' => 'Ana Gomez',
            'received_by_document' => '1234567',
            'customer_accepted' => true,
        ])->assertCreated();

        // Segunda visita (registrada después de la entrega anterior).
        $this->travel(1)->minutes();
        $second = $this->reception($client, now()->toDateString(), 52000);
        $this->secondWorkOrder = $this->workOrderFor($second, [
            ['type' => 'labor', 'description' => 'Revision de frenos', 'quantity' => 1, 'unit_price' => 80000],
        ]);

        // Otro vehículo: no debe aparecer.
        $this->completedWorkOrder();
    }

    private function reception(Client $client, string $date, int $mileage): Reception
    {
        return Reception::create([
            'client_id' => $client->id,
            'vehicle_id' => $this->vehicle->id,
            'reception_date' => $date,
            'mileage' => $mileage,
            'problem_description' => 'Mantenimiento',
        ]);
    }

    private function workOrderFor(Reception $reception, array $items): array
    {
        $budget = app(BudgetService::class)->create(['reception_id' => $reception->id], User::first()->id);

        foreach ($items as $item) {
            $this->postJson("/api/budgets/{$budget->id}/items", $item)->assertCreated();
        }

        $this->approveBudget($budget->id);

        return $this->createWorkOrder($budget->id);
    }

    private function historyUrl(string $suffix = ''): string
    {
        return "/api/vehicles/{$this->vehicle->id}/history{$suffix}";
    }

    public function test_summary_and_timeline(): void
    {
        $data = $this->getJson($this->historyUrl())->assertOk()->json('data');

        $this->assertSame('QAH123', $data['vehicle']['plate']);
        $this->assertSame('Ana Gomez', $data['vehicle']['client']['full_name']);

        $summary = $data['summary'];
        $this->assertSame(2, $summary['visits']);
        $this->assertSame(now()->subMonths(2)->toDateString(), $summary['first_visit']);
        $this->assertSame(now()->toDateString(), $summary['last_visit']);
        $this->assertSame(52000, $summary['last_mileage']);
        $this->assertSame(1, $summary['work_orders_completed']);
        $this->assertSame('0.00', $summary['balance_due']);
        $this->assertSame('220281.00', $summary['invoiced_total']);
        $this->assertArrayHasKey('economics', $summary);

        // La visita más reciente primero.
        $this->assertCount(2, $data['timeline']);
        [$latest, $previous] = $data['timeline'];

        $this->assertSame(52000, $latest['mileage_in']);
        $this->assertSame('pending', $latest['work_orders'][0]['status']);
        $this->assertNull($latest['work_orders'][0]['invoice']);
        $this->assertNull($latest['delivery']);

        $this->assertSame(40000, $previous['mileage_in']);
        $this->assertSame('delivered', $previous['status']);
        $this->assertSame('ENT-000001', $previous['delivery']['delivery_number']);
        $this->assertSame(40100, $previous['delivery']['mileage_out']);

        $workOrder = $previous['work_orders'][0];
        $this->assertSame('completed', $workOrder['status']);
        $this->assertCount(2, $workOrder['jobs']);
        $this->assertSame([['part_code' => $workOrder['parts_used'][0]['part_code'], 'name' => 'Filtro de aceite', 'quantity' => '1.700']], $workOrder['parts_used']);
        $this->assertSame('paid', $workOrder['invoice']['payment_status']);
        $this->assertSame('255.00', bcsub($workOrder['economics']['sale_total'], '200000', 2));

        $this->assertSame(2, $this->getJson($this->historyUrl() . '?per_page=1')->json('meta.pagination.lastPage'));
    }

    public function test_parts_used(): void
    {
        $this->getJson($this->historyUrl('/parts'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.work_order_code', $this->firstWorkOrder['code'])
            ->assertJsonPath('data.0.quantity', '1.700')
            ->assertJsonPath('data.0.total_price', '255.00')
            ->assertJsonPath('data.0.total_cost', '170.00');
    }

    public function test_mileage_readings(): void
    {
        $readings = $this->getJson($this->historyUrl('/mileage'))->assertOk()->json('data');

        $this->assertSame([40000, 40100, 52000], array_column($readings, 'mileage'));
        $this->assertSame(['reception', 'delivery', 'reception'], array_column($readings, 'source'));
        $this->assertSame([false, false, false], array_column($readings, 'decreased'));

        // Una lectura menor a la anterior se marca.
        $this->travel(1)->minutes();
        $this->reception($this->vehicle->client, now()->toDateString(), 30000);

        $readings = $this->getJson($this->historyUrl('/mileage'))->json('data');
        $this->assertTrue(end($readings)['decreased']);
    }

    public function test_costs_are_hidden_without_economics_permission(): void
    {
        $this->actingAsMechanic();

        $data = $this->getJson($this->historyUrl())->assertOk()->json('data');

        $this->assertArrayNotHasKey('economics', $data['summary']);
        $this->assertArrayNotHasKey('economics', $data['timeline'][1]['work_orders'][0]);
        // Lo facturado sí se ve.
        $this->assertSame('220281.00', $data['summary']['invoiced_total']);

        $part = $this->getJson($this->historyUrl('/parts'))->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('total_cost', $part);
        $this->assertSame('255.00', $part['total_price']);
    }

    public function test_history_access(): void
    {
        foreach (['mechanic', 'recepcionista', 'jefe-taller', 'cajero', 'deposito'] as $role) {
            $this->actingAsRole($role);
            $this->getJson($this->historyUrl())->assertOk();
        }

        Sanctum::actingAs(User::create([
            'name' => 'sin_rol',
            'first_name' => 'Sin',
            'last_name' => 'Rol',
            'email' => 'sin.rol@test.local',
            'password' => bcrypt('password'),
        ]));

        $this->getJson($this->historyUrl())->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->getJson($this->historyUrl('/parts'))->assertForbidden();
        $this->getJson($this->historyUrl('/mileage'))->assertForbidden();

        $this->actingAsAdmin();
        $this->getJson('/api/vehicles/999999/history')->assertNotFound()->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $this->getJson($this->historyUrl() . '?per_page=500')->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_vehicle_without_visits(): void
    {
        $empty = Vehicle::create([
            'client_id' => $this->vehicle->client_id,
            'brand_id' => $this->vehicle->brand_id,
            'vehicle_model_id' => $this->vehicle->vehicle_model_id,
            'plate' => 'QAH999',
            'mileage' => 15000,
        ]);

        $this->getJson("/api/vehicles/{$empty->id}/history")
            ->assertOk()
            ->assertJsonPath('data.summary.visits', 0)
            ->assertJsonPath('data.summary.first_visit', null)
            ->assertJsonPath('data.summary.last_mileage', 15000)
            ->assertJsonPath('data.summary.invoiced_total', '0.00')
            ->assertJsonCount(0, 'data.timeline');

        $this->getJson("/api/vehicles/{$empty->id}/history/mileage")->assertOk()->assertJsonCount(0, 'data');
    }
}

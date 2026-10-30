<?php

namespace Tests\Feature\MasterData;

use App\Models\Brand;
use App\Models\Client;
use App\Models\VehicleModel;
use Tests\Feature\Parts\PartsTestCase;

class VehicleTest extends PartsTestCase
{
    private Client $client;
    private Brand $brand;
    private VehicleModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();

        $this->client = Client::create([
            'document_number' => 'QA-3001',
            'first_name' => 'Ana',
            'last_name' => 'Gomez',
            'phone' => '+595981000333',
            'email' => 'qa-3001@example.com',
        ]);
        $this->brand = Brand::create(['name' => 'Toyota']);
        $this->model = VehicleModel::create(['brand_id' => $this->brand->id, 'name' => 'Hilux']);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'client_id' => $this->client->id,
            'brand_id' => $this->brand->id,
            'vehicle_model_id' => $this->model->id,
            'plate' => 'QAV123',
            'no_plate' => false,
            'year' => 2020,
            'color' => 'white',
            'fuel_type' => 'diesel',
            'transmission' => 'manual',
            'mileage' => 45000,
            ...$overrides,
        ];
    }

    public function test_crud_of_vehicles(): void
    {
        $vehicle = $this->postJson('/api/vehicles', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.plate', 'QAV123')
            ->json('data');

        $this->getJson('/api/vehicles?search=QAV')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/vehicles/{$vehicle['id']}")->assertOk()->assertJsonPath('data.client.id', $this->client->id);

        $this->patchJson("/api/vehicles/{$vehicle['id']}", $this->payload(['mileage' => 52000, 'color' => 'black']))
            ->assertOk()
            ->assertJsonPath('data.mileage', 52000);

        $this->deleteJson("/api/vehicles/{$vehicle['id']}")->assertOk();
        $this->getJson("/api/vehicles/{$vehicle['id']}")->assertNotFound()->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');

        // La chapa del eliminado queda libre.
        $this->postJson('/api/vehicles', $this->payload())->assertCreated();
    }

    public function test_optional_fields_can_be_cleared_on_update(): void
    {
        $vehicle = $this->postJson('/api/vehicles', $this->payload(['engine_number' => 'MOTOR12345', 'notes' => 'Llantas nuevas.']))
            ->assertCreated()
            ->json('data');

        $this->patchJson("/api/vehicles/{$vehicle['id']}", $this->payload([
            'year' => null,
            'color' => null,
            'engine_number' => null,
            'mileage' => null,
            'fuel_type' => null,
            'transmission' => null,
            'notes' => null,
        ]))
            ->assertOk()
            ->assertJsonPath('data.year', null)
            ->assertJsonPath('data.color', null)
            ->assertJsonPath('data.engine_number', null)
            ->assertJsonPath('data.mileage', null)
            ->assertJsonPath('data.fuel_type', null)
            ->assertJsonPath('data.transmission', null)
            ->assertJsonPath('data.notes', null);

        // La chapa sigue siendo obligatoria si el vehículo tiene chapa.
        $this->patchJson("/api/vehicles/{$vehicle['id']}", $this->payload(['plate' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plate']);
    }

    public function test_vehicle_without_plate_requires_chassis(): void
    {
        $this->postJson('/api/vehicles', $this->payload(['plate' => null, 'no_plate' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['chassis']);

        $this->postJson('/api/vehicles', $this->payload(['plate' => null, 'no_plate' => true, 'chassis' => '9BWZZZ377VT004251']))
            ->assertCreated()
            ->assertJsonPath('data.no_plate', true);
    }

    public function test_vehicle_validation(): void
    {
        $this->postJson('/api/vehicles', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id', 'brand_id', 'vehicle_model_id', 'no_plate']);

        $this->postJson('/api/vehicles', $this->payload(['year' => (int) date('Y') + 1]))->assertJsonValidationErrors(['year']);
        $this->postJson('/api/vehicles', $this->payload(['fuel_type' => 'nafta']))->assertJsonValidationErrors(['fuel_type']);
        $this->postJson('/api/vehicles', $this->payload(['transmission' => 'cvt']))->assertJsonValidationErrors(['transmission']);
        $this->postJson('/api/vehicles', $this->payload(['mileage' => -1]))->assertJsonValidationErrors(['mileage']);
        $this->postJson('/api/vehicles', $this->payload(['client_id' => 999999]))->assertJsonValidationErrors(['client_id']);
        $this->postJson('/api/vehicles', $this->payload(['plate' => 'QA V!']))->assertJsonValidationErrors(['plate']);
    }

    public function test_mechanic_can_view_but_not_change_vehicles(): void
    {
        $vehicle = $this->postJson('/api/vehicles', $this->payload())->json('data');

        $this->actingAsMechanic();

        $this->getJson('/api/vehicles')->assertOk();
        $this->getJson("/api/vehicles/{$vehicle['id']}")->assertOk();
        $this->postJson('/api/vehicles', $this->payload(['plate' => 'QAV999']))->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->patchJson("/api/vehicles/{$vehicle['id']}", $this->payload())->assertForbidden();
        $this->deleteJson("/api/vehicles/{$vehicle['id']}")->assertForbidden();
    }
}

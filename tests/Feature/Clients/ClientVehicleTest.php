<?php

namespace Tests\Feature\Clients;

use App\Models\Brand;
use App\Models\Client;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Support\Str;
use Tests\Feature\Parts\PartsTestCase;

class ClientVehicleTest extends PartsTestCase
{
    private Brand $brand;
    private VehicleModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brand = Brand::create(['name' => 'Toyota']);
        $this->model = VehicleModel::create(['brand_id' => $this->brand->id, 'name' => 'Hilux']);
    }

    private function createClient(): Client
    {
        return Client::create([
            'document_number' => 'DOC-' . Str::random(6),
            'first_name' => 'Ana',
            'last_name' => 'Gomez',
            'phone' => '+595981' . random_int(100000, 999999),
            'email' => Str::lower(Str::random(10)) . '@example.com',
        ]);
    }

    private function createVehicle(Client $client, string $plate): Vehicle
    {
        return Vehicle::create([
            'client_id' => $client->id,
            'brand_id' => $this->brand->id,
            'vehicle_model_id' => $this->model->id,
            'plate' => $plate,
        ]);
    }

    private function vehiclePayload(array $overrides = []): array
    {
        return [
            'brand_id' => $this->brand->id,
            'vehicle_model_id' => $this->model->id,
            'plate' => 'ABC123',
            'no_plate' => false,
            'year' => 2020,
            ...$overrides,
        ];
    }

    public function test_lists_only_the_vehicles_of_the_client(): void
    {
        $this->actingAsAdmin();

        $owner = $this->createClient();
        $other = $this->createClient();

        $this->createVehicle($owner, 'AAA111');
        $this->createVehicle($owner, 'BBB222');
        $this->createVehicle($other, 'CCC333');

        $this->getJson("/api/clients/{$owner->id}/vehicles")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 2);

        $this->getJson("/api/clients/{$owner->id}/vehicles?search=BBB")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.plate', 'BBB222');
    }

    public function test_creates_vehicle_for_the_client_in_the_url(): void
    {
        $this->actingAsAdmin();

        $owner = $this->createClient();
        $other = $this->createClient();

        // El client_id del body se ignora: manda la URL.
        $response = $this->postJson(
            "/api/clients/{$owner->id}/vehicles",
            $this->vehiclePayload(['client_id' => $other->id])
        )->assertCreated();

        $this->assertDatabaseHas('vehicles', [
            'id' => $response->json('data.id'),
            'client_id' => $owner->id,
            'plate' => 'ABC123',
        ]);
    }

    public function test_store_reuses_vehicle_validation(): void
    {
        $this->actingAsAdmin();

        $owner = $this->createClient();
        $this->createVehicle($owner, 'ABC123');

        $this->postJson("/api/clients/{$owner->id}/vehicles", $this->vehiclePayload())
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['plate']);

        $this->postJson("/api/clients/{$owner->id}/vehicles", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['brand_id', 'vehicle_model_id', 'no_plate'])
            ->assertJsonMissingValidationErrors(['client_id']);
    }

    public function test_show_requires_vehicle_to_belong_to_client(): void
    {
        $this->actingAsAdmin();

        $owner = $this->createClient();
        $other = $this->createClient();
        $vehicle = $this->createVehicle($owner, 'AAA111');

        $this->getJson("/api/clients/{$owner->id}/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $vehicle->id);

        $this->getJson("/api/clients/{$other->id}/vehicles/{$vehicle->id}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
    }

    public function test_missing_or_deleted_client_returns_not_found(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/clients/999999/vehicles')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');

        $client = $this->createClient();
        $client->delete();

        $this->postJson("/api/clients/{$client->id}/vehicles", $this->vehiclePayload())
            ->assertNotFound();
    }

    public function test_permissions_match_the_vehicle_module(): void
    {
        $this->actingAsAdmin();

        $owner = $this->createClient();
        $vehicle = $this->createVehicle($owner, 'AAA111');

        $this->actingAsMechanic();

        $this->getJson("/api/clients/{$owner->id}/vehicles")->assertOk();
        $this->getJson("/api/clients/{$owner->id}/vehicles/{$vehicle->id}")->assertOk();

        $this->postJson("/api/clients/{$owner->id}/vehicles", $this->vehiclePayload(['plate' => 'NEW999']))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_flat_vehicle_routes_still_work(): void
    {
        $this->actingAsAdmin();

        $owner = $this->createClient();

        $this->postJson('/api/vehicles', $this->vehiclePayload(['client_id' => $owner->id]))
            ->assertCreated();

        $this->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}

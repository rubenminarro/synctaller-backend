<?php

namespace Tests\Feature\MasterData;

use App\Models\Brand;
use App\Models\Client;
use App\Models\VehicleModel;
use Tests\Feature\Parts\PartsTestCase;

class BrandAndVehicleModelTest extends PartsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
    }

    public function test_crud_of_brands(): void
    {
        $brand = $this->postJson('/api/brands', ['name' => 'Toyota'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Toyota')
            ->json('data');

        $this->getJson('/api/brands?search=Toy')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/brands/{$brand['id']}")->assertOk();

        $this->patchJson("/api/brands/{$brand['id']}", ['name' => 'Toyota Motors'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Toyota Motors');

        $this->deleteJson("/api/brands/{$brand['id']}")->assertOk();
        $this->getJson("/api/brands/{$brand['id']}")->assertNotFound()->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');

        // El nombre del eliminado queda libre.
        $this->postJson('/api/brands', ['name' => 'Toyota Motors'])->assertCreated();
    }

    public function test_brand_validation(): void
    {
        $this->postJson('/api/brands', [])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->postJson('/api/brands', ['name' => 'X'])->assertJsonValidationErrors(['name']);
        $this->postJson('/api/brands', ['name' => 'Marca 123'])->assertJsonValidationErrors(['name']);

        $this->postJson('/api/brands', ['name' => 'Nissan'])->assertCreated();
        $this->postJson('/api/brands', ['name' => 'Nissan'])->assertJsonValidationErrors(['name']);
    }

    public function test_crud_of_vehicle_models(): void
    {
        $brand = $this->postJson('/api/brands', ['name' => 'Toyota'])->json('data');
        $other = $this->postJson('/api/brands', ['name' => 'Nissan'])->json('data');

        $model = $this->postJson('/api/vehicle-models', ['name' => 'Hilux SRV', 'brand_id' => $brand['id']])
            ->assertCreated()
            ->json('data');

        $this->getJson('/api/vehicle-models?search=Hilux')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/vehicle-models/{$model['id']}")->assertOk()->assertJsonPath('data.brand.id', $brand['id']);

        $this->patchJson("/api/vehicle-models/{$model['id']}", ['name' => 'Frontier', 'brand_id' => $other['id']])
            ->assertOk()
            ->assertJsonPath('data.name', 'Frontier')
            ->assertJsonPath('data.brand.id', $other['id']);

        $this->deleteJson("/api/vehicle-models/{$model['id']}")->assertOk();
        $this->getJson("/api/vehicle-models/{$model['id']}")->assertNotFound();
    }

    public function test_vehicle_model_validation(): void
    {
        $brand = $this->postJson('/api/brands', ['name' => 'Toyota'])->json('data');

        $this->postJson('/api/vehicle-models', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'brand_id']);
        $this->postJson('/api/vehicle-models', ['name' => 'Hilux', 'brand_id' => 999999])->assertJsonValidationErrors(['brand_id']);
        $this->postJson('/api/vehicle-models', ['name' => 'Hilux@', 'brand_id' => $brand['id']])->assertJsonValidationErrors(['name']);

        $this->postJson('/api/vehicle-models', ['name' => 'Hilux', 'brand_id' => $brand['id']])->assertCreated();
        $this->postJson('/api/vehicle-models', ['name' => 'Hilux', 'brand_id' => $brand['id']])->assertJsonValidationErrors(['name']);
    }

    public function test_vehicle_models_can_be_filtered_by_brand(): void
    {
        $toyota = $this->postJson('/api/brands', ['name' => 'Toyota'])->json('data');
        $nissan = $this->postJson('/api/brands', ['name' => 'Nissan'])->json('data');
        $this->postJson('/api/vehicle-models', ['name' => 'Hilux', 'brand_id' => $toyota['id']])->assertCreated();
        $this->postJson('/api/vehicle-models', ['name' => 'Corolla', 'brand_id' => $toyota['id']])->assertCreated();
        $this->postJson('/api/vehicle-models', ['name' => 'Frontier', 'brand_id' => $nissan['id']])->assertCreated();

        $this->getJson("/api/vehicle-models?brand_id={$toyota['id']}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.brand.id', $toyota['id'])
            ->assertJsonPath('data.1.brand.id', $toyota['id']);

        $this->getJson('/api/vehicle-models')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_brand_or_model_with_vehicles_cannot_be_deleted(): void
    {
        $brand = Brand::create(['name' => 'Toyota']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Hilux']);
        $client = Client::create([
            'document_number' => 'QA-4001',
            'first_name' => 'Ana',
            'last_name' => 'Gomez',
            'phone' => '+595981000444',
            'email' => 'qa-4001@example.com',
        ]);

        $vehicle = $this->postJson('/api/vehicles', [
            'client_id' => $client->id,
            'brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'plate' => 'QAB123',
            'no_plate' => false,
        ])->assertCreated()->json('data');

        $this->deleteJson("/api/brands/{$brand->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'BRAND_HAS_VEHICLES');

        $this->deleteJson("/api/vehicle-models/{$model->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'VEHICLE_MODEL_HAS_VEHICLES');

        $this->assertNotSoftDeleted('brands', ['id' => $brand->id, 'name' => 'Toyota']);
        $this->assertNotSoftDeleted('vehicle_models', ['id' => $model->id, 'name' => 'Hilux']);

        // Un vehículo eliminado ya no bloquea la baja.
        $this->deleteJson("/api/vehicles/{$vehicle['id']}")->assertOk();

        $this->deleteJson("/api/vehicle-models/{$model->id}")->assertOk();
        $this->deleteJson("/api/brands/{$brand->id}")->assertOk();
        $this->assertSoftDeleted('brands', ['id' => $brand->id]);
    }

    public function test_brand_with_models_cannot_be_deleted(): void
    {
        $brand = $this->postJson('/api/brands', ['name' => 'Toyota'])->json('data');
        $model = $this->postJson('/api/vehicle-models', ['name' => 'Hilux', 'brand_id' => $brand['id']])->json('data');

        $this->deleteJson("/api/brands/{$brand['id']}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'BRAND_HAS_MODELS');

        // Un modelo eliminado ya no bloquea la baja.
        $this->deleteJson("/api/vehicle-models/{$model['id']}")->assertOk();
        $this->deleteJson("/api/brands/{$brand['id']}")->assertOk();
    }

    public function test_mechanic_cannot_manage_brands_or_models(): void
    {
        $this->actingAsMechanic();

        $this->getJson('/api/brands')->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->getJson('/api/vehicle-models')->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
    }
}

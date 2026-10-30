<?php

namespace Tests\Feature\Receptions;

use App\Models\Brand;
use App\Models\CheckListItem;
use App\Models\Client;
use App\Models\ServiceCategory;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Support\Str;
use Tests\Feature\WorkOrderParts\WorkOrderPartsTestCase;

abstract class ReceptionTestCase extends WorkOrderPartsTestCase
{
    protected Client $client;
    protected Vehicle $vehicle;
    protected ServiceCategory $mechanical;
    protected ServiceCategory $electrical;
    protected ServiceCategory $tires;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->makeClient();
        $this->vehicle = $this->makeVehicle($this->client, 'QAR123');

        $this->mechanical = ServiceCategory::create(['code' => 'mechanical', 'name' => 'Mecánica']);
        $this->electrical = ServiceCategory::create(['code' => 'electrical', 'name' => 'Electricidad']);
        $this->tires = ServiceCategory::create(['code' => 'tires', 'name' => 'Neumáticos']);

        // Aceite: mecánica (obligatorio) · Luces: mecánica y electricidad · Presión: neumáticos.
        $oil = CheckListItem::create(['name' => 'Nivel de aceite', 'type' => 'boolean', 'required' => true]);
        $lights = CheckListItem::create(['name' => 'Luces', 'type' => 'text', 'required' => false]);
        $pressure = CheckListItem::create(['name' => 'Presion de neumaticos', 'type' => 'number', 'required' => false]);

        $oil->serviceCategories()->attach($this->mechanical);
        $lights->serviceCategories()->attach([$this->mechanical->id, $this->electrical->id]);
        $pressure->serviceCategories()->attach($this->tires);
    }

    protected function makeClient(): Client
    {
        return Client::create([
            'document_number' => 'DOC-' . Str::random(6),
            'first_name' => 'Ana',
            'last_name' => 'Gomez',
            'phone' => '+595981' . random_int(100000, 999999),
            'email' => Str::lower(Str::random(10)) . '@example.com',
        ]);
    }

    protected function makeVehicle(Client $client, string $plate): Vehicle
    {
        $brand = Brand::create(['name' => 'Marca ' . Str::random(6)]);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Modelo ' . Str::random(6)]);

        return Vehicle::create([
            'client_id' => $client->id,
            'brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'plate' => $plate,
        ]);
    }

    protected function receptionPayload(array $overrides = []): array
    {
        return [
            'client_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'service_category_ids' => [$this->mechanical->id, $this->electrical->id],
            'reception_date' => now()->toDateString(),
            'fuel_level' => 'half',
            'mileage' => 45000,
            'problem_description' => 'Ruido en el motor',
            ...$overrides,
        ];
    }

    protected function createReception(array $overrides = []): array
    {
        return $this->postJson('/api/receptions', $this->receptionPayload($overrides))->assertCreated()->json('data');
    }
}

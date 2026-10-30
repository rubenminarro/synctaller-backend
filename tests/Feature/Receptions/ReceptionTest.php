<?php

namespace Tests\Feature\Receptions;

use App\Enums\ReceptionStatus;
use App\Models\Reception;
use App\Models\ReceptionCheckList;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ReceptionTest extends ReceptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
    }

    private function checkList(array $reception): ReceptionCheckList
    {
        return ReceptionCheckList::where('reception_id', $reception['id'])->firstOrFail();
    }

    private function markAs(array $reception, ReceptionStatus $status): void
    {
        Reception::whereKey($reception['id'])->update(['status' => $status]);
    }

    public function test_store_generates_the_checklist_from_the_categories(): void
    {
        $reception = $this->createReception();

        $this->assertCount(2, $reception['service_categories']);

        // Aceite y luces (luces está en las dos categorías, pero se genera una vez).
        $names = $this->checkList($reception)->items()->with('checkListItem')->get()->pluck('checkListItem.name')->sort()->values();
        $this->assertSame(['Luces', 'Nivel de aceite'], $names->all());

        $this->getJson("/api/receptions/{$reception['id']}")->assertOk()->assertJsonPath('data.client.id', $this->client->id);
        $this->getJson('/api/receptions')->assertOk()->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_reception_resources_expose_raw_values_and_the_checklist(): void
    {
        $reception = $this->createReception(['estimated_delivery_date' => now()->addDays(2)->toDateString()]);
        $this->vehicle->load(['brand', 'vehicleModel']);

        $this->getJson("/api/receptions/{$reception['id']}")
            ->assertOk()
            ->assertJsonPath('data.reception_date', now()->toDateString())
            ->assertJsonPath('data.estimated_delivery_date', now()->addDays(2)->toDateString())
            ->assertJsonPath('data.mileage', 45000)
            ->assertJsonPath('data.problem_description', 'Ruido en el motor')
            ->assertJsonPath('data.client_id', $this->client->id)
            ->assertJsonPath('data.vehicle.brand', $this->vehicle->brand->name)
            ->assertJsonPath('data.vehicle.model', $this->vehicle->vehicleModel->name)
            ->assertJsonPath('data.check_list.id', $this->checkList($reception)->id)
            ->assertJsonPath('data.check_list.status', 'pending')
            // D3: approved_by se eliminó (nunca se asignaba).
            ->assertJsonMissingPath('data.approved_by');

        $this->getJson('/api/receptions')
            ->assertOk()
            ->assertJsonPath('data.0.reception_date', now()->toDateString())
            ->assertJsonPath('data.0.client.id', $this->client->id)
            ->assertJsonPath('data.0.vehicle.id', $this->vehicle->id)
            ->assertJsonPath('data.0.vehicle.model', $this->vehicle->vehicleModel->name);
    }

    public function test_fuel_level_cannot_be_null(): void
    {
        // La columna es NOT NULL DEFAULT empty: sin enviarlo queda en empty; en null se rechaza.
        $this->postJson('/api/receptions', $this->receptionPayload(['fuel_level' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fuel_level']);

        $payload = $this->receptionPayload();
        unset($payload['fuel_level']);
        $reception = $this->postJson('/api/receptions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.fuel.value', 'empty')
            ->json('data');

        $this->patchJson("/api/receptions/{$reception['id']}", ['fuel_level' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fuel_level']);
    }

    public function test_reception_validation(): void
    {
        $this->postJson('/api/receptions', [])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['client_id', 'vehicle_id', 'service_category_ids', 'reception_date']);

        $this->postJson('/api/receptions', $this->receptionPayload(['service_category_ids' => []]))->assertJsonValidationErrors(['service_category_ids']);
        $this->postJson('/api/receptions', $this->receptionPayload(['fuel_level' => 'lleno']))->assertJsonValidationErrors(['fuel_level']);
        $this->postJson('/api/receptions', $this->receptionPayload(['mileage' => -1]))->assertJsonValidationErrors(['mileage']);

        // Vehículo de otro cliente.
        $other = $this->makeVehicle($this->makeClient(), 'QAR999');
        $this->postJson('/api/receptions', $this->receptionPayload(['vehicle_id' => $other->id]))->assertJsonValidationErrors(['vehicle_id']);

        // El vehículo ya tiene una recepción abierta.
        $this->createReception();
        $this->postJson('/api/receptions', $this->receptionPayload())->assertJsonValidationErrors(['vehicle_id']);
    }

    public function test_every_open_status_blocks_a_new_reception_of_the_vehicle(): void
    {
        $reception = $this->createReception();

        foreach (ReceptionStatus::openStatuses() as $status) {
            $this->markAs($reception, $status);

            $this->postJson('/api/receptions', $this->receptionPayload())
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'VALIDATION_ERROR')
                ->assertJsonPath('errors.vehicle_id.0', 'El vehículo ya tiene una recepción activa.');
        }

        // Entregada o cancelada: el vehículo puede volver a ingresar.
        $this->markAs($reception, ReceptionStatus::DELIVERED);
        $second = $this->createReception();

        $this->markAs($second, ReceptionStatus::CANCELLED);
        $this->createReception();

        $this->assertSame(3, Reception::where('vehicle_id', $this->vehicle->id)->count());
    }

    public function test_update_cannot_move_an_open_reception_to_a_vehicle_with_another_open_one(): void
    {
        $otherVehicle = $this->makeVehicle($this->client, 'QAR222');

        $first = $this->createReception();
        $second = $this->createReception(['vehicle_id' => $otherVehicle->id]);

        $this->markAs($second, ReceptionStatus::COMPLETED);

        $this->patchJson("/api/receptions/{$first['id']}", ['vehicle_id' => $otherVehicle->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.vehicle_id.0', 'El vehículo ya tiene otra recepción activa.');

        // Con la otra recepción entregada, sí se puede.
        $this->markAs($second, ReceptionStatus::DELIVERED);

        $this->patchJson("/api/receptions/{$first['id']}", ['vehicle_id' => $otherVehicle->id])
            ->assertOk()
            ->assertJsonPath('data.vehicle.id', $otherVehicle->id);
    }

    public function test_a_closed_reception_cannot_be_edited_even_after_the_vehicle_returns(): void
    {
        $old = $this->createReception();
        $this->markAs($old, ReceptionStatus::DELIVERED);

        // El vehículo reingresa con una recepción nueva (abierta).
        $this->createReception();

        // La entregada no se modifica (decisión del 2026-10-22): el rechazo es por estar cerrada, no por la
        // recepción abierta del vehículo.
        $this->patchJson("/api/receptions/{$old['id']}", ['observations' => 'Corrección posterior'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RECEPTION_CLOSED');
    }

    public function test_changing_categories_regenerates_the_checklist_only_while_pending(): void
    {
        $reception = $this->createReception();

        $this->patchJson("/api/receptions/{$reception['id']}", ['service_category_ids' => [$this->tires->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.service_categories');

        $names = $this->checkList($reception)->items()->with('checkListItem')->get()->pluck('checkListItem.name')->all();
        $this->assertSame(['Presion de neumaticos'], $names);

        $this->markAs($reception, ReceptionStatus::IN_PROGRESS);

        $this->patchJson("/api/receptions/{$reception['id']}", ['service_category_ids' => [$this->mechanical->id]])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RECEPTION_CATEGORIES_NOT_EDITABLE');

        // Los demás datos sí se pueden actualizar.
        $this->patchJson("/api/receptions/{$reception['id']}", ['observations' => 'Cliente espera'])
            ->assertOk()
            ->assertJsonPath('data.observations', 'Cliente espera');
    }

    public function test_only_unprocessed_receptions_can_be_deleted(): void
    {
        $pending = $this->createReception();
        $this->deleteJson("/api/receptions/{$pending['id']}")->assertOk();
        $this->getJson("/api/receptions/{$pending['id']}")->assertNotFound()->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');

        $processed = $this->createReception();
        $this->markAs($processed, ReceptionStatus::IN_PROGRESS);

        $this->deleteJson("/api/receptions/{$processed['id']}")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RECEPTION_NOT_DELETABLE');
        $this->assertNotSoftDeleted('receptions', ['id' => $processed['id']]);
    }

    public function test_checklist_values_and_status(): void
    {
        $reception = $this->createReception();
        $checkList = $this->checkList($reception);
        $items = $checkList->items()->with('checkListItem')->get()->keyBy('checkListItem.name');
        $url = "/api/reception-check-lists/{$checkList->id}";

        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.items');

        // Tipo boolean admite booleanos; tipo text admite good / bad / na.
        $this->patchJson($url, ['items' => [['id' => $items['Nivel de aceite']->id, 'value' => 'abc']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.value']);
        $this->patchJson($url, ['items' => [['id' => $items['Luces']->id, 'value' => 'regular']]])
            ->assertJsonValidationErrors(['items.0.value']);
        $this->patchJson($url, ['items' => []])->assertJsonValidationErrors(['items']);

        $this->patchJson($url, ['items' => [
            ['id' => $items['Nivel de aceite']->id, 'value' => true],
            ['id' => $items['Luces']->id, 'value' => 'bad', 'observation' => 'Faro izquierdo quemado'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_checklist_of_a_processed_reception_cannot_be_changed(): void
    {
        $reception = $this->createReception();
        $checkList = $this->checkList($reception);
        $item = $checkList->items()->first();

        $this->markAs($reception, ReceptionStatus::COMPLETED);

        $this->patchJson("/api/reception-check-lists/{$checkList->id}", ['items' => [['id' => $item->id, 'value' => null, 'observation' => 'x']]])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RECEPTION_CHECKLIST_NOT_EDITABLE');
    }

    public function test_reception_photos(): void
    {
        Storage::fake('public');

        $reception = $this->createReception();
        $url = "/api/receptions/{$reception['id']}/photos";

        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['photos']);
        $this->postJson($url, ['photos' => [['file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')]]])
            ->assertJsonValidationErrors(['photos.0.file']);

        $photo = $this->postJson($url, ['photos' => [
            ['file' => UploadedFile::fake()->image('frente.jpg'), 'description' => 'Frente'],
        ]])
            ->assertCreated()
            ->assertJsonPath('data.0.description', 'Frente')
            ->json('data.0');

        $path = \App\Models\ReceptionPhoto::findOrFail($photo['id'])->path;
        Storage::disk('public')->assertExists($path);

        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');

        $this->patchJson("{$url}/{$photo['id']}", ['description' => 'Frente con raspon'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Frente con raspon');

        // Foto de otra recepción en la URL.
        $other = $this->createReception(['vehicle_id' => $this->makeVehicle($this->client, 'QAR777')->id]);
        $this->deleteJson("/api/receptions/{$other['id']}/photos/{$photo['id']}")->assertNotFound();

        $this->deleteJson("{$url}/{$photo['id']}")->assertOk();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_mechanic_permissions_on_receptions(): void
    {
        $reception = $this->createReception();

        $this->actingAsMechanic();

        $this->getJson('/api/receptions')->assertOk();
        $this->getJson("/api/receptions/{$reception['id']}")->assertOk();
        $this->patchJson("/api/receptions/{$reception['id']}", ['observations' => 'Revisado'])->assertOk();
        $this->postJson('/api/receptions', $this->receptionPayload())->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->deleteJson("/api/receptions/{$reception['id']}")->assertForbidden();
    }
}

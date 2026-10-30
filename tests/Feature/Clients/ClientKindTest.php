<?php

namespace Tests\Feature\Clients;

use App\Models\Brand;
use App\Models\Client;
use App\Models\Reception;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Parts\PartsTestCase;

/**
 * Cliente persona o empresa (M1): validaciones por tipo, display_name en lugar de
 * nombre y apellido, búsquedas por razón social, cambio de tipo y baja en uso.
 */
class ClientKindTest extends PartsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
    }

    private function personPayload(array $overrides = []): array
    {
        return [
            'document_number' => 'CI-' . Str::random(6),
            'first_name' => 'Ana',
            'last_name' => 'Gomez',
            'email' => Str::lower(Str::random(8)) . '@example.com',
            'phone' => '+595981' . random_int(100000, 999999),
            ...$overrides,
        ];
    }

    private function companyPayload(array $overrides = []): array
    {
        return [
            'kind' => 'company',
            'document_number' => '80012345-' . random_int(1, 9),
            'business_name' => 'Seguros del Sur S.A.',
            'email' => Str::lower(Str::random(8)) . '@example.com',
            'phone' => '+595211' . random_int(100000, 999999),
            'address' => 'Av. Mcal. López 1234, Asunción',
            ...$overrides,
        ];
    }

    private function vehicleOf(Client $client): Vehicle
    {
        $brand = Brand::create(['name' => 'Marca ' . Str::random(6)]);

        return Vehicle::create([
            'client_id' => $client->id,
            'brand_id' => $brand->id,
            'vehicle_model_id' => VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Modelo ' . Str::random(6)])->id,
            'plate' => Str::upper(Str::random(7)),
        ]);
    }

    public function test_a_person_is_created_as_before(): void
    {
        $this->postJson('/api/clients', $this->personPayload())
            ->assertCreated()
            ->assertJsonPath('data.kind.value', 'person')
            ->assertJsonPath('data.kind.label', 'Persona')
            ->assertJsonPath('data.first_name', 'Ana')
            ->assertJsonPath('data.business_name', null)
            ->assertJsonPath('data.display_name', 'Ana Gomez');
    }

    public function test_a_company_is_created_with_its_business_name(): void
    {
        $this->postJson('/api/clients', $this->companyPayload())
            ->assertCreated()
            ->assertJsonPath('data.kind.value', 'company')
            ->assertJsonPath('data.business_name', 'Seguros del Sur S.A.')
            ->assertJsonPath('data.first_name', null)
            ->assertJsonPath('data.last_name', null)
            ->assertJsonPath('data.display_name', 'Seguros del Sur S.A.')
            ->assertJsonPath('data.address', 'Av. Mcal. López 1234, Asunción');
    }

    public function test_fields_are_validated_by_kind(): void
    {
        $this->postJson('/api/clients', $this->companyPayload(['business_name' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('business_name');

        $this->postJson('/api/clients', $this->companyPayload(['first_name' => 'Ana']))
            ->assertStatus(422)->assertJsonValidationErrors('first_name');

        $this->postJson('/api/clients', $this->personPayload(['business_name' => 'Ana S.A.']))
            ->assertStatus(422)->assertJsonValidationErrors('business_name');

        $this->postJson('/api/clients', $this->personPayload(['first_name' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('first_name');

        $this->postJson('/api/clients', $this->personPayload(['kind' => 'otro']))
            ->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    public function test_the_kind_changes_only_without_receptions_or_invoices(): void
    {
        $id = $this->postJson('/api/clients', $this->personPayload())->json('data.id');

        // Sin los datos del tipo nuevo, no cambia.
        $this->patchJson("/api/clients/{$id}", ['kind' => 'company'])
            ->assertStatus(422)->assertJsonValidationErrors('business_name');

        $this->patchJson("/api/clients/{$id}", ['kind' => 'company', 'business_name' => 'Gomez Hnos. S.R.L.'])
            ->assertOk()
            ->assertJsonPath('data.kind.value', 'company')
            ->assertJsonPath('data.first_name', null)
            ->assertJsonPath('data.display_name', 'Gomez Hnos. S.R.L.');

        // Editar una empresa sin cambiar el tipo: no pide nombre ni apellido.
        $this->patchJson("/api/clients/{$id}", ['business_name' => 'Gomez Hermanos S.R.L.'])->assertOk();
        $this->patchJson("/api/clients/{$id}", ['first_name' => 'Ana'])
            ->assertStatus(422)->assertJsonValidationErrors('first_name');

        $client = Client::findOrFail($id);
        Reception::create(['client_id' => $id, 'vehicle_id' => $this->vehicleOf($client)->id, 'reception_date' => now()->toDateString()]);

        $this->patchJson("/api/clients/{$id}", ['kind' => 'person', 'first_name' => 'Ana', 'last_name' => 'Gomez'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CLIENT_KIND_NOT_EDITABLE');

        $this->assertSame('Gomez Hermanos S.R.L.', $client->refresh()->display_name);
    }

    public function test_a_client_in_use_is_not_deleted(): void
    {
        $id = $this->postJson('/api/clients', $this->companyPayload())->json('data.id');
        $vehicle = $this->vehicleOf(Client::findOrFail($id));

        $this->deleteJson("/api/clients/{$id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'CLIENT_IN_USE');

        $vehicle->delete();

        $this->deleteJson("/api/clients/{$id}")->assertOk();
        $this->assertSoftDeleted('clients', ['id' => $id]);
    }

    public function test_a_company_is_shown_and_found_by_its_business_name(): void
    {
        $id = $this->postJson('/api/clients', $this->companyPayload())->json('data.id');
        $client = Client::findOrFail($id);
        $vehicle = $this->vehicleOf($client);
        $reception = Reception::create(['client_id' => $id, 'vehicle_id' => $vehicle->id, 'reception_date' => now()->toDateString()]);

        $this->getJson("/api/receptions/{$reception->id}")
            ->assertOk()
            ->assertJsonPath('data.client.full_name', 'Seguros del Sur S.A.');
        $this->getJson("/api/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('data.client.full_name', 'Seguros del Sur S.A.');

        $this->getJson('/api/clients?search=Sur S.A')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/clients?first_name=Seguros')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/vehicles?client_first_name=Seguros')->assertOk()->assertJsonPath('data.0.id', $vehicle->id);
        $this->getJson('/api/vehicles?search=Seguros')->assertOk()->assertJsonPath('data.0.id', $vehicle->id);
        $this->getJson('/api/receptions?search=Seguros')->assertOk()->assertJsonPath('data.0.id', $reception->id);
    }

    public function test_clients_are_filtered_by_kind(): void
    {
        $company = $this->postJson('/api/clients', $this->companyPayload())->json('data.id');
        $person = $this->postJson('/api/clients', $this->personPayload())->json('data.id');

        $companies = collect($this->getJson('/api/clients?kind=company&per_page=100')->assertOk()->json('data'));
        $this->assertContains($company, $companies->pluck('id'));
        $this->assertNotContains($person, $companies->pluck('id'));
        $this->assertSame(['company'], $companies->pluck('kind.value')->unique()->values()->all());

        $people = collect($this->getJson('/api/clients?kind=person&per_page=100')->assertOk()->json('data'));
        $this->assertContains($person, $people->pluck('id'));
        $this->assertNotContains($company, $people->pluck('id'));

        $this->getJson('/api/clients?kind=nadie')->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    public function test_the_database_requires_a_unique_email_and_the_document(): void
    {
        // D7 y D8: además de la validación, la base exige documento y email, y el email único.
        $client = Client::findOrFail($this->postJson('/api/clients', $this->personPayload())->json('data.id'));
        $row = collect($client->getAttributes())->only(['kind', 'first_name', 'last_name', 'phone'])->all();

        foreach ([
            ['document_number' => 'CI-UNICO-1', 'email' => $client->email],
            ['document_number' => null, 'email' => 'otro@example.com'],
            ['document_number' => 'CI-UNICO-2', 'email' => null],
        ] as $attributes) {
            try {
                DB::table('clients')->insert($row + $attributes + ['created_at' => now(), 'updated_at' => now()]);
                $this->fail('La base aceptó ' . json_encode($attributes));
            } catch (QueryException) {
                $this->assertDatabaseMissing('clients', ['document_number' => $attributes['document_number'] ?? 'x', 'email' => $attributes['email'] ?? 'x']);
            }
        }

        // Dado de baja, el email queda libre (sufijo //deleted_).
        $this->deleteJson("/api/clients/{$client->id}")->assertOk();
        $this->postJson('/api/clients', $this->personPayload(['email' => $client->email]))->assertCreated();
    }

    public function test_the_greeting_keeps_the_first_name_of_a_person(): void
    {
        $person = Client::create($this->personPayload());
        $company = Client::create([...$this->companyPayload(), 'kind' => 'company']);

        $this->assertSame('Ana', $person->greeting_name);
        $this->assertSame('Ana Gomez', $person->display_name);
        $this->assertSame('Seguros del Sur S.A.', $company->greeting_name);
    }
}

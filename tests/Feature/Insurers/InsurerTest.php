<?php

namespace Tests\Feature\Insurers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Insurer;
use Illuminate\Support\Str;
use Tests\Feature\Parts\PartsTestCase;

/**
 * Aseguradoras (M2): alta con cliente nuevo o existente, avisos del cliente
 * (decisión D), estados, contactos, efecto sobre el cliente y permisos.
 */
class InsurerTest extends PartsTestCase
{
    private function newClientData(array $overrides = []): array
    {
        return [
            'document_number' => '8001' . random_int(1000, 9999) . '-' . random_int(1, 9),
            'business_name' => 'Seguros del Sur S.A.',
            'email' => Str::lower(Str::random(8)) . '@example.com',
            'phone' => '+595211' . random_int(100000, 999999),
            ...$overrides,
        ];
    }

    private function companyClient(array $overrides = []): Client
    {
        return Client::create([...$this->newClientData(), 'kind' => 'company', ...$overrides]);
    }

    private function createInsurer(array $body = []): array
    {
        return $this->postJson('/api/insurers', ['client' => $this->newClientData(), ...$body])->assertCreated()->json('data');
    }

    public function test_an_insurer_is_created_with_its_new_client_without_notifications(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/insurers', ['client' => $this->newClientData(), 'payment_term_days' => 30])
            ->assertCreated()
            ->assertJsonPath('data.code', 'ASE-000001')
            ->assertJsonPath('data.status.value', 'active')
            ->assertJsonPath('data.payment_term_days', 30)
            ->assertJsonPath('data.client.kind.value', 'company')
            ->assertJsonPath('data.client.display_name', 'Seguros del Sur S.A.')
            ->assertJsonPath('data.client.notifications_enabled', false)
            ->assertJsonPath('meta', null);

        $this->assertTrue(AuditLog::where('auditable_type', 'insurer')->where('event', 'created')->exists());
    }

    public function test_an_existing_company_keeps_its_notifications_with_a_warning(): void
    {
        $this->actingAsAdmin();
        $client = $this->companyClient();

        $this->postJson('/api/insurers', ['client_id' => $client->id])
            ->assertCreated()
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.client.notifications_enabled', true)
            ->assertJsonCount(1, 'meta.warnings');

        $this->assertTrue($client->refresh()->notifications_enabled);
    }

    public function test_the_client_is_validated(): void
    {
        $this->actingAsAdmin();
        $client = $this->companyClient();

        $this->postJson('/api/insurers', [])->assertStatus(422)->assertJsonValidationErrors(['client_id', 'client']);
        $this->postJson('/api/insurers', ['client_id' => $client->id, 'client' => $this->newClientData()])
            ->assertStatus(422)->assertJsonValidationErrors('client_id');
        $this->postJson('/api/insurers', ['client' => $this->newClientData(['business_name' => null, 'first_name' => 'Ana'])])
            ->assertStatus(422)->assertJsonValidationErrors(['client.business_name', 'client.first_name']);

        $person = Client::create(['document_number' => 'QA-8001', 'first_name' => 'Ana', 'last_name' => 'Gomez', 'phone' => '0981123456', 'email' => 'qa-8001@example.com']);
        $this->postJson('/api/insurers', ['client_id' => $person->id])
            ->assertStatus(422)->assertJsonPath('error_code', 'INSURER_CLIENT_NOT_COMPANY');

        $this->postJson('/api/insurers', ['client_id' => $client->id])->assertCreated();
        $this->postJson('/api/insurers', ['client_id' => $client->id])
            ->assertStatus(409)->assertJsonPath('error_code', 'INSURER_CLIENT_ALREADY_LINKED');
    }

    public function test_status_transitions(): void
    {
        $this->actingAsAdmin();
        $id = $this->createInsurer()['id'];

        $this->postJson("/api/insurers/{$id}/activate")
            ->assertStatus(422)->assertJsonPath('error_code', 'INSURER_INVALID_STATUS_TRANSITION');
        $this->postJson("/api/insurers/{$id}/deactivate")->assertOk()->assertJsonPath('data.status.value', 'inactive');
        $this->getJson('/api/insurers?status=inactive')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson("/api/insurers/{$id}/activate")->assertOk()->assertJsonPath('data.status.value', 'active');

        $this->patchJson("/api/insurers/{$id}", ['payment_term_days' => 60, 'notes' => 'Liquida por OT.'])
            ->assertOk()->assertJsonPath('data.payment_term_days', 60);
        $this->getJson('/api/insurers?search=del Sur')->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_contacts_keep_a_single_primary(): void
    {
        $this->actingAsAdmin();
        $id = $this->createInsurer()['id'];

        $first = $this->postJson("/api/insurers/{$id}/contacts", ['name' => 'Laura Benítez', 'role' => 'claims', 'is_primary' => true])
            ->assertCreated()->assertJsonPath('data.role.label', 'Siniestros')->json('data.id');
        $second = $this->postJson("/api/insurers/{$id}/contacts", ['name' => 'Pedro Ortiz', 'role' => 'billing', 'is_primary' => true])
            ->assertCreated()->json('data.id');

        $this->getJson("/api/insurers/{$id}")
            ->assertOk()
            ->assertJsonPath('data.contacts.0.id', $second)
            ->assertJsonPath('data.contacts.0.is_primary', true)
            ->assertJsonPath('data.contacts.1.is_primary', false);

        $this->patchJson("/api/insurers/{$id}/contacts/{$first}", ['email' => 'lbenitez@example.com', 'phone' => null])
            ->assertOk()->assertJsonPath('data.email', 'lbenitez@example.com');
        $this->postJson("/api/insurers/{$id}/contacts", ['name' => 'X', 'role' => 'otro'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'role']);

        // Contacto de otra aseguradora por la URL anidada.
        $other = $this->createInsurer(['client' => $this->newClientData(['business_name' => 'Otra S.A.'])])['id'];
        $this->patchJson("/api/insurers/{$other}/contacts/{$first}", ['notes' => 'x y z'])->assertNotFound();

        $this->deleteJson("/api/insurers/{$id}/contacts/{$first}")->assertOk();
        $this->getJson("/api/insurers/{$id}/contacts")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_linked_client_is_protected_until_the_insurer_is_deleted(): void
    {
        $this->actingAsAdmin();
        $insurer = $this->createInsurer();
        $clientId = $insurer['client']['id'];

        $this->deleteJson("/api/clients/{$clientId}")
            ->assertStatus(409)->assertJsonPath('error_code', 'CLIENT_LINKED_TO_INSURER');
        $this->patchJson("/api/clients/{$clientId}", ['kind' => 'person', 'first_name' => 'Ana', 'last_name' => 'Gomez'])
            ->assertStatus(422)->assertJsonPath('error_code', 'CLIENT_KIND_NOT_EDITABLE');

        $this->deleteJson("/api/insurers/{$insurer['id']}")->assertOk();
        $this->assertSoftDeleted('insurers', ['id' => $insurer['id']]);

        // Una aseguradora dada de baja no retiene a su cliente.
        $this->postJson('/api/insurers', ['client_id' => $clientId])->assertCreated()->assertJsonPath('data.code', 'ASE-000002');
    }

    public function test_permissions_by_role(): void
    {
        $this->actingAsRole('recepcionista');
        $id = $this->createInsurer()['id'];
        $this->postJson("/api/insurers/{$id}/deactivate")->assertOk();

        $this->actingAsRole('cajero');
        $this->getJson('/api/insurers')->assertOk();
        $this->getJson("/api/insurers/{$id}/contacts")->assertOk();
        $this->postJson('/api/insurers', ['client' => $this->newClientData()])->assertForbidden();
        $this->postJson("/api/insurers/{$id}/contacts", ['name' => 'Laura', 'role' => 'claims'])->assertForbidden();

        $this->actingAsRole('jefe-taller');
        $this->getJson("/api/insurers/{$id}")->assertOk();
        $this->deleteJson("/api/insurers/{$id}")->assertForbidden();

        $this->actingAsMechanic();
        $this->getJson('/api/insurers')->assertForbidden();

        $this->actingAsRole('deposito');
        $this->getJson("/api/insurers/{$id}")->assertForbidden();

        $this->assertSame(1, Insurer::count());
    }
}

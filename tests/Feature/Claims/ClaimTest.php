<?php

namespace Tests\Feature\Claims;

use App\Models\Brand;
use App\Models\Client;
use App\Models\Reception;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Invoices\InvoiceTestCase;

/**
 * Siniestros (M3): alta y asegurado, unicidades, edición, cancelación,
 * sincronización con la entrega y la recepción, aseguradora en uso,
 * visibilidad en la recepción y permisos.
 */
class ClaimTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function insurer(): int
    {
        return $this->postJson('/api/insurers', ['client' => [
            'document_number' => '8009' . random_int(1000, 9999) . '-' . random_int(1, 9),
            'business_name' => 'Seguros del Sur S.A.',
            'email' => Str::lower(Str::random(8)) . '@example.com',
            'phone' => '+595211' . random_int(100000, 999999),
        ]])->assertCreated()->json('data.id');
    }

    private function openReception(): Reception
    {
        $brand = Brand::create(['name' => 'Marca ' . Str::random(6)]);
        $client = Client::create([
            'document_number' => '4' . random_int(100000, 999999),
            'first_name' => 'Ana',
            'last_name' => 'Gomez',
            'phone' => '0981' . random_int(100000, 999999),
            'email' => 'ana@example.com',
        ]);
        $vehicle = Vehicle::create([
            'client_id' => $client->id,
            'brand_id' => $brand->id,
            'vehicle_model_id' => VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Modelo ' . Str::random(6)])->id,
            'plate' => Str::upper(Str::random(7)),
        ]);

        return Reception::create(['client_id' => $client->id, 'vehicle_id' => $vehicle->id, 'reception_date' => now()->toDateString()]);
    }

    private function claimBody(int $receptionId, int $insurerId, array $overrides = []): array
    {
        return [
            'reception_id' => $receptionId,
            'insurer_id' => $insurerId,
            'claim_type' => 'own',
            'loss_date' => now()->subDays(3)->toDateString(),
            'claim_number' => 'S-' . random_int(10000, 99999),
            ...$overrides,
        ];
    }

    public function test_an_own_claim_copies_the_insured_from_the_client(): void
    {
        $this->actingAsAdmin();
        $reception = $this->openReception();

        $this->postJson('/api/claims', $this->claimBody($reception->id, $this->insurer(), ['adjuster_name' => 'Perito López']))
            ->assertCreated()
            ->assertJsonPath('data.code', 'SIN-000001')
            ->assertJsonPath('data.status.value', 'open')
            ->assertJsonPath('data.insured.client_id', $reception->client_id)
            ->assertJsonPath('data.insured.name', 'Ana Gomez')
            ->assertJsonPath('data.insured.email', 'ana@example.com')
            ->assertJsonPath('data.adjuster.name', 'Perito López')
            ->assertJsonPath('data.deductible_amount', null)
            ->assertJsonPath('data.insurer.name', 'Seguros del Sur S.A.');

        $this->getJson("/api/receptions/{$reception->id}")->assertOk()->assertJsonPath('data.claim.code', 'SIN-000001');
        $this->getJson("/api/receptions/{$reception->id}/claim")->assertOk()->assertJsonPath('data.code', 'SIN-000001');

        // El mecánico ve la recepción, pero no el siniestro.
        $this->actingAsMechanic();
        $this->getJson("/api/receptions/{$reception->id}")->assertOk()->assertJsonMissingPath('data.claim');
    }

    public function test_a_third_party_claim_requires_the_insured_name(): void
    {
        $this->actingAsAdmin();
        $reception = $this->openReception();
        $insurerId = $this->insurer();

        $this->postJson('/api/claims', $this->claimBody($reception->id, $insurerId, ['claim_type' => 'third_party']))
            ->assertStatus(422)->assertJsonValidationErrors('insured_name');

        $this->postJson('/api/claims', $this->claimBody($reception->id, $insurerId, [
            'claim_type' => 'third_party',
            'insured_name' => 'Carlos Benítez',
            'deductible_amount' => 0,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.insured.client_id', null)
            ->assertJsonPath('data.insured.name', 'Carlos Benítez')
            ->assertJsonPath('data.deductible_amount', '0.00');
    }

    public function test_one_current_claim_per_reception_and_unique_numbers(): void
    {
        $this->actingAsAdmin();
        $reception = $this->openReception();
        $insurerId = $this->insurer();

        $id = $this->postJson('/api/claims', $this->claimBody($reception->id, $insurerId, ['claim_number' => 'S-100']))
            ->assertCreated()->json('data.id');

        $this->postJson('/api/claims', $this->claimBody($reception->id, $insurerId))
            ->assertStatus(409)->assertJsonPath('error_code', 'RECEPTION_ALREADY_HAS_CLAIM');
        $this->postJson('/api/claims', $this->claimBody($this->openReception()->id, $insurerId, ['claim_number' => 'S-100']))
            ->assertStatus(409)->assertJsonPath('error_code', 'CLAIM_NUMBER_DUPLICATE');

        // Cancelado queda en el historial: se puede abrir otro en la misma recepción y reutilizar el número.
        $this->postJson("/api/claims/{$id}/cancel", ['reason' => 'La aseguradora rechazó la cobertura.'])
            ->assertOk()->assertJsonPath('data.status.value', 'cancelled');
        $this->postJson('/api/claims', $this->claimBody($reception->id, $insurerId, ['claim_number' => 'S-100']))
            ->assertCreated()->assertJsonPath('data.code', 'SIN-000002');
    }

    public function test_the_insurer_must_be_active_and_the_reception_open(): void
    {
        $this->actingAsAdmin();
        $insurerId = $this->insurer();

        $this->postJson("/api/insurers/{$insurerId}/deactivate")->assertOk();
        $this->postJson('/api/claims', $this->claimBody($this->openReception()->id, $insurerId))
            ->assertStatus(422)->assertJsonPath('error_code', 'INSURER_INACTIVE');
        $this->postJson("/api/insurers/{$insurerId}/activate")->assertOk();

        $reception = $this->openReception();
        $this->postJson("/api/receptions/{$reception->id}/cancel", ['reason' => 'El cliente desistió.'])->assertOk();
        $this->postJson('/api/claims', $this->claimBody($reception->id, $insurerId))
            ->assertStatus(422)->assertJsonPath('error_code', 'RECEPTION_CLOSED');
    }

    public function test_editing_and_cancelling(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/claims', $this->claimBody($this->openReception()->id, $this->insurer()))->json('data.id');

        $this->patchJson("/api/claims/{$id}", ['deductible_amount' => 500000, 'inspected_at' => now()->toDateTimeString()])
            ->assertOk()->assertJsonPath('data.deductible_amount', '500000.00');
        $this->patchJson("/api/claims/{$id}", ['deductible_amount' => null])
            ->assertOk()->assertJsonPath('data.deductible_amount', null);
        $this->patchJson("/api/claims/{$id}", ['insurer_id' => 1, 'claim_type' => 'third_party'])
            ->assertStatus(422)->assertJsonValidationErrors(['insurer_id', 'claim_type']);
        $this->postJson("/api/claims/{$id}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson("/api/claims/{$id}/cancel", ['reason' => 'Error de carga.'])->assertOk();
        $this->patchJson("/api/claims/{$id}", ['coverage_notes' => 'x y z'])
            ->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_NOT_EDITABLE');
        $this->postJson("/api/claims/{$id}/cancel", ['reason' => 'Otra vez.'])
            ->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_NOT_CANCELLABLE');
    }

    public function test_the_claim_follows_the_delivery_and_the_reception(): void
    {
        $this->actingAsAdmin();
        $this->createStamp()->assertCreated();

        $workOrder = $this->completedWorkOrder();
        $id = $this->postJson('/api/claims', $this->claimBody($workOrder['reception_id'], $this->insurer()))->json('data.id');

        $draft = $this->generateInvoice($workOrder);
        $this->patchJson("/api/invoices/{$draft['id']}", ['customer_tax_id' => '1234567'])->assertOk();
        $invoice = $this->postJson("/api/invoices/{$draft['id']}/issue")->assertOk()->json('data');
        $this->postJson("/api/invoices/{$invoice['id']}/payments", ['amount' => (int) $invoice['totals']['total'], 'method' => 'cash'])->assertCreated();

        $delivery = $this->postJson("/api/receptions/{$workOrder['reception_id']}/delivery", [
            'final_mileage' => 46000, 'fuel_level' => 'half', 'received_by_name' => 'Ana Gomez',
            'received_by_document' => '1234567', 'customer_accepted' => true,
        ])->assertCreated()->json('data');
        $this->getJson("/api/claims/{$id}")->assertJsonPath('data.status.value', 'closed');

        $this->postJson("/api/vehicle-deliveries/{$delivery['id']}/cancel", ['reason' => 'Se registró por error.'])->assertOk();
        $this->getJson("/api/claims/{$id}")->assertJsonPath('data.status.value', 'open')->assertJsonPath('data.closed_at', null);

        // Con la recepción cancelada, el siniestro se cancela con ella.
        $reception = $this->openReception();
        $other = $this->postJson('/api/claims', $this->claimBody($reception->id, $this->insurer()))->json('data.id');
        $this->postJson("/api/receptions/{$reception->id}/cancel", ['reason' => 'El cliente desistió.'])->assertOk();
        $this->getJson("/api/claims/{$other}")
            ->assertJsonPath('data.status.value', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Recepción cancelada: El cliente desistió.');
    }

    public function test_an_insurer_with_claims_is_not_deleted(): void
    {
        $this->actingAsAdmin();
        $insurerId = $this->insurer();
        $id = $this->postJson('/api/claims', $this->claimBody($this->openReception()->id, $insurerId))->json('data.id');
        $this->postJson("/api/claims/{$id}/cancel", ['reason' => 'Error de carga.'])->assertOk();

        $this->deleteJson("/api/insurers/{$insurerId}")
            ->assertStatus(409)->assertJsonPath('error_code', 'INSURER_IN_USE');
    }

    public function test_permissions_by_role(): void
    {
        $this->actingAsAdmin();
        $insurerId = $this->insurer();
        $receptionId = $this->openReception()->id;

        $this->actingAsRole('jefe-taller');
        $id = $this->postJson('/api/claims', $this->claimBody($receptionId, $insurerId))->assertCreated()->json('data.id');

        $this->actingAsRole('cajero');
        $this->getJson('/api/claims?status=open')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/claims/{$id}")->assertOk();
        $this->patchJson("/api/claims/{$id}", ['coverage_notes' => 'Revisar.'])->assertForbidden();

        $this->actingAsMechanic();
        $this->getJson('/api/claims')->assertForbidden();
        $this->getJson("/api/receptions/{$receptionId}/claim")->assertForbidden();

        $this->actingAsRole('deposito');
        $this->getJson("/api/claims/{$id}")->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Claims;

use App\Models\Insurer;
use App\Models\InvoiceItem;
use App\Models\InvoiceStamp;
use App\Models\Reception;

/**
 * Facturas por responsable (M9): desde el reparto confirmado, una por cliente y
 * versión, con la regla de cantidad 1 (Ejemplo 5); emisión con R8 y el control
 * del deducible (A); corrección del reparto con facturas (P6 con P10); entrega
 * por factura; claves `invoices[]` (F) y el bloqueo del deducible.
 */
class AllocationInvoiceTest extends ClaimTestCase
{
    /** Reparto del Ejemplo 1 confirmado: aseguradora 1.800.000 y dueño 970.000 (sin IVA). */
    private function confirmedExample(int|null $deductible = 0): array
    {
        if (!InvoiceStamp::exists()) {
            $this->createStamp()->assertCreated();
        }
        [$workOrder, $budget, $claim] = $this->completedClaimWorkOrder(deductible: $deductible);
        $allocation = $this->postJson($this->url($workOrder))->assertCreated()->json('data');
        $this->postJson($this->url($workOrder, $allocation['id'], 'confirm'))->assertOk();
        $insurerClientId = Insurer::findOrFail($claim->insurer_id)->client_id;
        $ownerClientId = Reception::findOrFail($workOrder['reception_id'])->client_id;

        return [$workOrder, $allocation, $claim, $insurerClientId, $ownerClientId, $budget];
    }

    private function invoice(array $workOrder, array $allocation, int $clientId)
    {
        return $this->postJson($this->url($workOrder, $allocation['id'], 'invoices'), ['client_id' => $clientId]);
    }

    private function deliver(array $workOrder)
    {
        Reception::whereKey($workOrder['reception_id'])->update(['mileage' => 45000]);

        return $this->postJson("/api/receptions/{$workOrder['reception_id']}/delivery", [
            'final_mileage' => 46000, 'fuel_level' => 'half', 'received_by_name' => 'Ana Gomez',
            'received_by_document' => '1234567', 'customer_accepted' => true,
        ]);
    }

    public function test_example_1_is_invoiced_to_each_responsible(): void
    {
        $this->actingAsAdmin();
        [$workOrder, $allocation, , $insurerClientId, $ownerClientId] = $this->confirmedExample();

        // Aseguradora: chapería en parte (cantidad 1) y paragolpes entero (2 × 500.000). A crédito, con su plazo.
        $insurer = $this->invoice($workOrder, $allocation, $insurerClientId)
            ->assertCreated()
            ->assertJsonPath('data.allocation_id', $allocation['id'])
            ->assertJsonPath('data.client_id', $insurerClientId)
            ->assertJsonPath('data.condition.value', 'credit')
            ->assertJsonPath('data.totals.total', '1980000.00')
            ->json('data');
        $this->assertSame(now()->addDays(30)->toDateString(), substr($insurer['due_date'], 0, 10));
        $lines = InvoiceItem::where('invoice_id', $insurer['id'])->orderBy('id')->get();
        $this->assertSame(['1.000', '2.000'], $lines->map(fn ($l) => (string) $l->quantity)->all());
        $this->assertStringContainsString('(parte; cantidad real 1', $lines[0]->description);
        $this->assertSame(['800000.00', '1000000.00'], $lines->map(fn ($l) => (string) $l->base_total)->all());
        $this->assertNotNull($lines[0]->allocation_line_id);

        // Dueño: lo no cubierto, su línea y el extra. Al contado.
        $owner = $this->invoice($workOrder, $allocation, $ownerClientId)
            ->assertCreated()
            ->assertJsonPath('data.condition.value', 'cash')
            ->assertJsonPath('data.totals.total', '1067000.00')
            ->json('data');

        $this->invoice($workOrder, $allocation, $ownerClientId)->assertStatus(409)->assertJsonPath('error_code', 'WORK_ORDER_ALREADY_INVOICED');
        $other = Reception::findOrFail($this->openReception()->id)->client_id;
        $this->invoice($workOrder, $allocation, $other)->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_CLIENT_NOT_RESPONSIBLE');

        foreach ([$insurer, $owner] as $draft) {
            $this->postJson("/api/invoices/{$draft['id']}/issue")->assertOk();
        }

        // La OT muestra las dos (F); `invoice` sigue siendo la última. El listado filtra por OT.
        $this->getJson("/api/work-orders/{$workOrder['id']}")
            ->assertOk()
            ->assertJsonPath('data.invoices_count', 2)
            ->assertJsonPath('data.invoices.0.client_id', $insurerClientId)
            ->assertJsonPath('data.invoices.1.client_id', $ownerClientId)
            ->assertJsonPath('data.invoice.id', $owner['id']);
        $this->getJson("/api/invoices?work_order_id={$workOrder['id']}")->assertOk()->assertJsonCount(2, 'data');
        $this->get("/api/invoices/{$insurer['id']}/pdf")->assertOk();

        $this->actingAsMechanic();
        $this->getJson("/api/work-orders/{$workOrder['id']}")->assertOk()->assertJsonMissingPath('data.invoices')->assertJsonMissingPath('data.invoices_count');
    }

    public function test_delivery_is_checked_by_invoice(): void
    {
        $this->actingAsAdmin();
        [$workOrder, $allocation, , $insurerClientId, $ownerClientId] = $this->confirmedExample();

        $owner = $this->invoice($workOrder, $allocation, $ownerClientId)->assertCreated()->json('data');
        $this->postJson("/api/invoices/{$owner['id']}/issue")->assertOk();

        // Falta la factura de la aseguradora.
        $this->deliver($workOrder)->assertStatus(422)->assertJsonPath('error_code', 'DELIVERY_WORK_ORDER_NOT_FULLY_INVOICED');

        $insurer = $this->invoice($workOrder, $allocation, $insurerClientId)->assertCreated()->json('data');
        $this->postJson("/api/invoices/{$insurer['id']}/issue")->assertOk();

        // La del dueño es al contado y está impaga; la de la aseguradora, a crédito, puede quedar impaga.
        $this->deliver($workOrder)->assertStatus(422)->assertJsonPath('error_code', 'DELIVERY_INVOICE_NOT_PAID');
        $this->postJson("/api/invoices/{$owner['id']}/payments", ['amount' => 1067000, 'method' => 'cash'])->assertCreated();
        $this->deliver($workOrder)->assertCreated();

        // Historial del vehículo: las dos facturas y el resumen por destinatario.
        $vehicleId = Reception::findOrFail($workOrder['reception_id'])->vehicle_id;
        $history = $this->getJson("/api/vehicles/{$vehicleId}/history")->assertOk()->json('data');
        $this->assertSame('0.00', $history['summary']['by_recipient']['owner']['balance_due']);
        $this->assertSame('1980000.00', $history['summary']['by_recipient']['others']['balance_due']);
        $this->assertCount(2, $history['timeline'][0]['work_orders'][0]['invoices']);
    }

    public function test_the_deductible_blocks_invoicing_and_delivery(): void
    {
        $this->actingAsAdmin();
        [$workOrder, $allocation, $claim, $insurerClientId, $ownerClientId] = $this->confirmedExample();

        // Factura del dueño generada con deducible 0; el deducible sube después (no hay factura a la aseguradora).
        $owner = $this->invoice($workOrder, $allocation, $ownerClientId)->assertCreated()->json('data');
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_amount' => 500000])->assertOk();

        $this->postJson("/api/invoices/{$owner['id']}/issue")->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_DEDUCTIBLE_MODE_UNDEFINED');
        $this->invoice($workOrder, $allocation, $insurerClientId)->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_DEDUCTIBLE_MODE_UNDEFINED');
        $this->deliver($workOrder)->assertStatus(422)->assertJsonPath('error_code', 'DELIVERY_CLAIM_DEDUCTIBLE_PENDING');

        // Desconocido tampoco.
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_amount' => null])->assertOk();
        $this->invoice($workOrder, $allocation, $insurerClientId)->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_DEDUCTIBLE_UNKNOWN');
        $this->deliver($workOrder)->assertStatus(422)->assertJsonPath('error_code', 'DELIVERY_CLAIM_DEDUCTIBLE_PENDING');

        // Con una factura vigente a la aseguradora el deducible no cambia.
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_amount' => 0])->assertOk();
        $this->invoice($workOrder, $allocation, $insurerClientId)->assertCreated();
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_amount' => 100000])
            ->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_DEDUCTIBLE_LOCKED');
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_amount' => 0, 'notes' => null])->assertOk();
    }

    public function test_correcting_an_invoiced_allocation_needs_credit_notes(): void
    {
        $this->actingAsAdmin();
        [$workOrder, $allocation, , $insurerClientId] = $this->confirmedExample();
        $insurer = $this->invoice($workOrder, $allocation, $insurerClientId)->assertCreated()->json('data');

        // El borrador cuenta: con facturado neto la versión anterior no se sustituye.
        $second = $this->postJson($this->url($workOrder))->assertCreated()->json('data');
        $this->postJson($this->url($workOrder, $second['id'], 'confirm'))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ALLOCATION_HAS_INVOICES')
            ->assertJsonPath('errors.invoices.0.invoice_id', $insurer['id'])
            ->assertJsonPath('errors.invoices.0.status', 'draft');

        // Emitida, se acredita entera con una nota de crédito externa (P10) y la nueva versión se confirma.
        $issued = $this->postJson("/api/invoices/{$insurer['id']}/issue")->assertOk()->json('data');
        $this->postJson("/api/invoices/{$issued['id']}/credit-notes", [
            'number' => '001-002-0000001',
            'issue_date' => now()->toDateString(),
            'reason' => 'La aseguradora pidió refacturar.',
            'items' => collect($issued['items'])->map(fn ($item) => ['invoice_item_id' => $item['id'], 'amount' => (int) $item['total']])->all(),
        ])->assertCreated();
        $this->postJson($this->url($workOrder, $second['id'], 'confirm'))->assertOk();

        // Se factura de nuevo desde la versión vigente.
        $this->invoice($workOrder, $second, $insurerClientId)->assertCreated()->assertJsonPath('data.allocation_id', $second['id']);
        $this->invoice($workOrder, $allocation, $insurerClientId)->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_NOT_CONFIRMED');
    }

    public function test_only_the_cashier_invoices(): void
    {
        $this->actingAsAdmin();
        [$workOrder, $allocation, , $insurerClientId] = $this->confirmedExample();

        $this->actingAsRole('recepcionista');
        $this->invoice($workOrder, $allocation, $insurerClientId)->assertForbidden();

        $this->actingAsRole('cajero');
        $this->invoice($workOrder, $allocation, $insurerClientId)->assertCreated();
    }
}

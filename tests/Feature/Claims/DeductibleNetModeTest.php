<?php

namespace Tests\Feature\Claims;

use App\Models\Claim;
use App\Models\Insurer;
use App\Models\InvoiceItem;
use App\Models\Reception;

/**
 * Modalidad neta del deducible (M10): la aseguradora recibe lo cubierto menos el deducible sin IVA y el dueño, el
 * deducible en una línea propia de su factura. Detrás del interruptor insurer.deductible_net_mode (apagado, rige A).
 * Ejemplo 7 de la especificación, IVA del deducible por siniestro (V15), deducible mayor que lo cubierto (V16) y R11.
 */
class DeductibleNetModeTest extends ClaimTestCase
{
    /** Siniestro abierto con la modalidad neta y el Ejemplo 1 completado sobre él. */
    private function netClaimWorkOrder(int $deductible = 220000, ?bool $includesTax = true): array
    {
        config(['insurer.deductible_net_mode' => true]);
        $reception = $this->openReception();
        $this->postJson('/api/claims', $this->claimBody($reception->id, $this->insurer(), [
            'claim_number' => 'S-123',
            'deductible_amount' => $deductible,
            'deductible_billing_mode' => 'net_of_deductible',
            'deductible_includes_tax' => $includesTax,
        ]))->assertCreated()
            ->assertJsonPath('data.deductible_billing_mode.value', 'net_of_deductible')
            ->assertJsonPath('data.deductible_includes_tax', $includesTax)
            ->assertJsonPath('data.deductible_net_mode_available', true);

        [$workOrder, $budget, $claim] = $this->completedClaimWorkOrder(reception: $reception);
        $this->createStamp()->assertCreated();

        return [$workOrder, $budget, $claim];
    }

    private function amounts(array $allocation): array
    {
        return collect($allocation['lines'])
            ->mapWithKeys(fn (array $line) => [$line['description'] . '|' . $line['portion_type']['value'] => $line['amount']])
            ->sortKeys()
            ->all();
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

    public function test_example_7_is_allocated_invoiced_and_delivered(): void
    {
        $this->actingAsAdmin();
        [$workOrder, , $claim] = $this->netClaimWorkOrder();

        // El deducible (220.000 con IVA, 200.000 sin IVA) sale de lo cubierto de la chapería y pasa al dueño.
        $draft = $this->postJson($this->url($workOrder))->assertCreated()->assertJsonPath('data.deductible_amount', '220000.00')->json('data');
        $expected = [
            'Alineación|owner_responsibility' => '150000.00',
            'Chapería de puerta|insurer_covered' => '600000.00',
            'Chapería de puerta|owner_deductible' => '200000.00',
            'Chapería de puerta|owner_uncovered' => '200000.00',
            'Clips de moldura|owner_responsibility' => '20000.00',
            'Paragolpes|insurer_covered' => '1000000.00',
            'Pintura|owner_uncovered' => '600000.00',
        ];
        ksort($expected);
        $this->assertSame($expected, $this->amounts($draft));
        $this->assertTrue(collect($draft['checks'])->every(fn (array $check) => $check['ok']));
        $this->assertSame(['insurer' => '1600000.00', 'owner' => '1170000.00'], collect($draft['summary']['responsibles'])->pluck('amount', 'role')->sortKeys()->all());

        // El deducible se factura: no queda con el tratamiento fiscal pendiente (ni en el reparto ni en el resumen).
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))->assertOk()->assertJsonPath('data.summary.deductible_fiscal_pending', false);
        $this->getJson("/api/claims/{$claim->id}/summary")->assertOk()->assertJsonPath('data.allocations.totals.deductible_fiscal_pending', false);
        $insurerClientId = Insurer::findOrFail($claim->insurer_id)->client_id;
        $ownerClientId = Reception::findOrFail($workOrder['reception_id'])->client_id;

        // Aseguradora: 1.600.000 + IVA. Dueño: 970.000 + el deducible (1 × 220.000 con IVA) = 1.287.000.
        $insurer = $this->invoice($workOrder, $draft, $insurerClientId)->assertCreated()->assertJsonPath('data.totals.total', '1760000.00')->json('data');
        $owner = $this->invoice($workOrder, $draft, $ownerClientId)->assertCreated()->assertJsonPath('data.totals.total', '1287000.00')->json('data');
        $line = InvoiceItem::where('invoice_id', $owner['id'])->where('description', 'Deducible de siniestro N.º S-123')->sole();
        $this->assertSame(['1.000', '200000.00', '220000.00'], [(string) $line->quantity, (string) $line->base_total, (string) $line->total]);

        // Con una factura vigente a la aseguradora, ni la modalidad ni el IVA del deducible cambian.
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_includes_tax' => false])
            ->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_DEDUCTIBLE_LOCKED');
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_billing_mode' => null])
            ->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_DEDUCTIBLE_LOCKED');

        // Entrega: rigen las facturas (la del dueño, al contado, cobrada); el deducible ya no la bloquea.
        foreach ([$insurer, $owner] as $draftInvoice) {
            $this->postJson("/api/invoices/{$draftInvoice['id']}/issue")->assertOk();
        }
        $this->deliver($workOrder)->assertStatus(422)->assertJsonPath('error_code', 'DELIVERY_INVOICE_NOT_PAID');
        $this->postJson("/api/invoices/{$owner['id']}/payments", ['amount' => 1287000, 'method' => 'cash'])->assertCreated();
        $this->deliver($workOrder)->assertCreated();
    }

    public function test_the_switch_keeps_rule_a_while_off(): void
    {
        $this->actingAsAdmin();
        $reception = $this->openReception();
        $insurerId = $this->insurer();

        // Apagado (por defecto) no se elige la modalidad, ni en el siniestro ni en la aseguradora.
        $this->postJson('/api/claims', $this->claimBody($reception->id, $insurerId, ['deductible_billing_mode' => 'net_of_deductible']))
            ->assertStatus(422)->assertJsonValidationErrors('deductible_billing_mode');
        $this->patchJson("/api/insurers/{$insurerId}", ['deductible_billing_mode' => 'net_of_deductible'])
            ->assertStatus(422)->assertJsonValidationErrors('deductible_billing_mode');
        $this->getJson("/api/insurers/{$insurerId}")->assertOk()->assertJsonPath('data.deductible_net_mode_available', false);

        // Aunque el siniestro la tenga cargada, con el interruptor apagado no se factura ni se entrega (A).
        [$workOrder, , $claim] = $this->completedClaimWorkOrder(deductible: 220000, reception: $reception);
        $claim->update(['deductible_billing_mode' => 'net_of_deductible', 'deductible_includes_tax' => true]);
        $draft = $this->postJson($this->url($workOrder))->assertCreated()->json('data');
        $this->assertArrayNotHasKey('Chapería de puerta|owner_deductible', $this->amounts($draft));
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))->assertOk();

        $this->createStamp()->assertCreated();
        $ownerClientId = Reception::findOrFail($workOrder['reception_id'])->client_id;
        $this->invoice($workOrder, $draft, $ownerClientId)->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_DEDUCTIBLE_MODE_UNDEFINED');
        $this->deliver($workOrder)->assertStatus(422)->assertJsonPath('error_code', 'DELIVERY_CLAIM_DEDUCTIBLE_PENDING');
    }

    public function test_the_tax_of_the_deductible_and_rule_r11(): void
    {
        $this->actingAsAdmin();
        [$workOrder, $budget, $claim] = $this->netClaimWorkOrder(includesTax: null);

        // Sin saber si incluye el IVA no se calcula su parte: no se confirma, no se factura y no se entrega.
        $draft = $this->postJson($this->url($workOrder))->assertCreated()->json('data');
        $this->assertFalse(collect($draft['checks'])->firstWhere('rule', 'deductible_tax')['ok']);
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))
            ->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_DEDUCTIBLE_TAX_UNDEFINED');
        $this->deliver($workOrder)->assertStatus(422)->assertJsonPath('error_code', 'DELIVERY_CLAIM_DEDUCTIBLE_PENDING');

        // Sin IVA, el importe informado ya es la base: 220.000 pasan al dueño.
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_includes_tax' => false])->assertOk();
        $this->deleteJson($this->url($workOrder, $draft['id']))->assertOk();
        $draft = $this->postJson($this->url($workOrder))->assertCreated()->json('data');
        $this->assertSame('220000.00', $this->amounts($draft)['Chapería de puerta|owner_deductible']);

        // R11: las porciones del deducible deben sumarlo. Se devuelven 100.000 a la aseguradora (dentro de lo
        // autorizado, sin tocar la parte del dueño): el deducible queda en 120.000.
        $shift = ['owner_deductible' => -100000, 'insurer_covered' => 100000];
        $lines = collect($draft['lines'])->map(fn (array $line) => [
            'source_type' => $line['source']['type'],
            'source_id' => $line['source']['id'],
            'portion_type' => $line['portion_type']['value'],
            'amount' => $line['description'] === 'Chapería de puerta'
                ? (float) $line['amount'] + ($shift[$line['portion_type']['value']] ?? 0)
                : $line['amount'],
        ])->all();
        $this->patchJson($this->url($workOrder, $draft['id']), ['lines' => $lines])->assertOk();
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))
            ->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_DEDUCTIBLE_MISMATCH');

        // Confirmado, el siniestro cambia el IVA del deducible antes de facturar: el reparto ya no coincide.
        $this->deleteJson($this->url($workOrder, $draft['id']))->assertOk();
        $draft = $this->postJson($this->url($workOrder))->assertCreated()->json('data');
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))->assertOk();
        $this->patchJson("/api/claims/{$claim->id}", ['deductible_includes_tax' => true])->assertOk();
        $ownerClientId = Reception::findOrFail($workOrder['reception_id'])->client_id;
        $this->invoice($workOrder, $draft, $ownerClientId)->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_DEDUCTIBLE_MISMATCH');
    }

    public function test_a_deductible_above_the_covered_amount_is_rejected(): void
    {
        $this->actingAsAdmin();
        // V16 sin respuesta: 2.000.000 sin IVA contra 1.800.000 cubiertos.
        [$workOrder] = $this->netClaimWorkOrder(deductible: 2000000, includesTax: false);

        $draft = $this->postJson($this->url($workOrder))->assertCreated()->json('data');
        $this->assertSame('1800000.00', collect($draft['lines'])->where('portion_type.value', 'owner_deductible')->sum('amount') . '.00');
        $this->postJson($this->url($workOrder, $draft['id'], 'confirm'))
            ->assertStatus(422)->assertJsonPath('error_code', 'ALLOCATION_DEDUCTIBLE_EXCEEDS_COVERED');
    }

    public function test_a_claim_takes_the_default_mode_of_its_insurer(): void
    {
        $this->actingAsAdmin();
        config(['insurer.deductible_net_mode' => true]);
        $insurerId = $this->insurer();
        $this->patchJson("/api/insurers/{$insurerId}", ['deductible_billing_mode' => 'net_of_deductible'])
            ->assertOk()->assertJsonPath('data.deductible_billing_mode.value', 'net_of_deductible');

        $claim = $this->postJson('/api/claims', $this->claimBody($this->openReception()->id, $insurerId))
            ->assertCreated()->assertJsonPath('data.deductible_billing_mode.value', 'net_of_deductible')->json('data');
        $other = $this->postJson('/api/claims', $this->claimBody($this->openReception()->id, $insurerId, ['deductible_billing_mode' => null]))
            ->assertCreated()->assertJsonPath('data.deductible_billing_mode', null)->json('data');

        $this->assertSame('net_of_deductible', Claim::findOrFail($claim['id'])->deductible_billing_mode->value);
        $this->assertNull(Claim::findOrFail($other['id'])->deductible_billing_mode);
    }
}

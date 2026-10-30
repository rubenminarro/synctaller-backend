<?php

namespace Tests\Feature\Invoices;

use App\Exceptions\InvoiceException;
use App\Models\InvoiceCreditNote;
use App\Models\InvoiceStamp;

/**
 * Notas de crédito emitidas fuera del sistema y registradas sobre una factura
 * (P10): por línea y parcial, hasta el saldo, con su efecto en el saldo, el
 * estado de cobro, la anulación de la factura y el reporte de ventas.
 */
class CreditNoteTest extends InvoiceTestCase
{
    /**
     * Factura emitida de completedWorkOrder: embrague 220.000, tornillos 5.500 y aceite 1,7 × 165 (con IVA).
     */
    private function issuedInvoice(): array
    {
        if (!InvoiceStamp::exists()) {
            $this->createStamp()->assertCreated();
        }
        $invoice = $this->readyInvoice();

        return $this->postJson("/api/invoices/{$invoice['id']}/issue")->assertOk()->json('data');
    }

    private function body(array $invoice, array $lines, array $overrides = []): array
    {
        return [
            'number' => '001-001-' . random_int(1000000, 9999999),
            'stamp_number' => '12345678',
            'issue_date' => now()->toDateString(),
            'reason' => 'El cliente reclamó la mano de obra.',
            'items' => collect($lines)->map(fn ($amount, $description) => [
                'invoice_item_id' => $this->item($invoice, $description)['id'],
                'amount' => $amount,
            ])->values()->all(),
            ...$overrides,
        ];
    }

    private function register(array $invoice, array $body)
    {
        return $this->postJson("/api/invoices/{$invoice['id']}/credit-notes", $body);
    }

    public function test_a_credit_note_is_registered_by_line_and_lowers_the_balance(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->issuedInvoice();
        $total = $invoice['totals']['total'];

        // La mitad de la mano de obra: base proporcional (100.000 de 200.000).
        $first = $this->register($invoice, $this->body($invoice, ['Cambio de embrague' => 110000]))
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'registered')
            ->assertJsonPath('data.total', '110000.00')
            ->assertJsonPath('data.total_10', '110000.00')
            ->assertJsonPath('data.iva_10', '10000.00')
            ->assertJsonPath('data.items.0.base_amount', '100000.00')
            ->json('data');

        $this->getJson("/api/invoices/{$invoice['id']}")
            ->assertOk()
            ->assertJsonPath('data.payment_summary.credited_total', '110000.00')
            ->assertJsonPath('data.payment_summary.balance', bcsub($total, '110000', 2))
            ->assertJsonPath('data.payment_summary.status', 'unpaid')
            ->assertJsonPath('data.credit_notes.0.id', $first['id']);

        // Lo que le queda a la línea, exacto; más de eso no.
        $this->register($invoice, $this->body($invoice, ['Cambio de embrague' => 110001]))
            ->assertStatus(422)->assertJsonPath('error_code', 'CREDIT_NOTE_EXCEEDS_LINE');
        $this->register($invoice, $this->body($invoice, ['Cambio de embrague' => 110000]))
            ->assertCreated()->assertJsonPath('data.items.0.base_amount', '100000.00');

        // Una línea de otra factura.
        $other = $this->issuedInvoice();
        $this->register($invoice, [...$this->body($invoice, []), 'items' => [['invoice_item_id' => $this->item($other, 'Tornillos')['id'], 'amount' => 1000]]])
            ->assertStatus(422)->assertJsonPath('error_code', 'CREDIT_NOTE_ITEM_MISMATCH');

        $this->getJson("/api/invoices/{$invoice['id']}/credit-notes")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/invoices/{$other['id']}/credit-notes/{$first['id']}")->assertNotFound();
    }

    public function test_it_never_exceeds_the_balance_and_paid_or_credited_status(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->issuedInvoice();
        $total = $invoice['totals']['total'];

        // Cobrado 200.000: solo se acredita lo que falta.
        $this->postJson("/api/invoices/{$invoice['id']}/payments", ['amount' => 200000, 'method' => 'cash', 'amount_received' => 200000, 'payment_date' => now()->toDateString()])
            ->assertCreated();
        $this->register($invoice, $this->body($invoice, ['Cambio de embrague' => 220000]))
            ->assertStatus(422)->assertJsonPath('error_code', 'CREDIT_NOTE_EXCEEDS_BALANCE');

        // Pagos más notas saldan la factura: pagada.
        $rest = (int) bcsub($total, '200000', 0);
        $this->register($invoice, $this->body($invoice, ['Cambio de embrague' => $rest]))->assertCreated();
        $this->getJson("/api/invoices/{$invoice['id']}")
            ->assertJsonPath('data.payment_summary.balance', '0.00')
            ->assertJsonPath('data.payment_summary.status', 'paid');
        $this->postJson("/api/invoices/{$invoice['id']}/payments", ['amount' => 1, 'method' => 'cash', 'amount_received' => 1, 'payment_date' => now()->toDateString()])
            ->assertStatus(422);

        // Sin pagos, saldada solo con notas: acreditada.
        $credited = $this->issuedInvoice();
        $this->register($credited, $this->body($credited, collect($credited['items'])->mapWithKeys(fn ($item) => [$item['description'] => (int) $item['total']])->all()))
            ->assertCreated();
        $this->getJson("/api/invoices/{$credited['id']}")
            ->assertJsonPath('data.payment_summary.balance', '0.00')
            ->assertJsonPath('data.payment_summary.status', 'credited');
        // El listado precarga lo acreditado (withSum) y da el mismo saldo.
        $listed = collect($this->getJson('/api/invoices?status=issued&per_page=50')->assertOk()->json('data'))->firstWhere('id', $credited['id']);
        $this->assertSame('0.00', $listed['balance']);
        $this->assertSame('credited', $listed['payment_status']);
    }

    public function test_a_credited_invoice_is_not_cancelled_and_a_credit_note_is_cancelled_with_a_reason(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->issuedInvoice();
        $note = $this->register($invoice, $this->body($invoice, ['Tornillos' => 5500]))->assertCreated()->json('data');

        $this->postJson("/api/invoices/{$invoice['id']}/cancel", ['reason' => 'Se emitió por error.'])
            ->assertStatus(422)->assertJsonPath('error_code', 'INVOICE_HAS_CREDIT_NOTES');

        $url = "/api/invoices/{$invoice['id']}/credit-notes/{$note['id']}/cancel";
        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson($url, ['reason' => 'Se cargó en la factura equivocada.'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Se cargó en la factura equivocada.');
        $this->postJson($url, ['reason' => 'Otra vez.'])->assertStatus(422)->assertJsonPath('error_code', 'CREDIT_NOTE_NOT_CANCELLABLE');

        // Anulada no cuenta: el saldo vuelve y la factura se puede anular; el número se libera.
        $this->getJson("/api/invoices/{$invoice['id']}")->assertJsonPath('data.payment_summary.credited_total', '0.00');
        $this->register($invoice, $this->body($invoice, ['Tornillos' => 5500], ['number' => $note['number']]))->assertCreated();

        try {
            InvoiceCreditNote::findOrFail($note['id'])->update(['reason' => 'Otro motivo']);
            $this->fail('Una nota de crédito registrada es inmutable.');
        } catch (InvoiceException $e) {
            $this->assertSame('CREDIT_NOTE_IMMUTABLE', $e->getErrorCode());
        }
    }

    public function test_validations(): void
    {
        $this->actingAsAdmin();
        $this->createStamp()->assertCreated();
        $draft = $this->readyInvoice();
        $this->register($draft, $this->body($draft, ['Tornillos' => 1000]))
            ->assertStatus(422)->assertJsonPath('error_code', 'INVOICE_NOT_CREDITABLE');

        $invoice = $this->postJson("/api/invoices/{$draft['id']}/issue")->assertOk()->json('data');
        $this->register($invoice, $this->body($invoice, ['Tornillos' => 1000], ['issue_date' => now()->subDay()->toDateString()]))
            ->assertStatus(422)->assertJsonPath('error_code', 'CREDIT_NOTE_DATE_BEFORE_INVOICE');
        $this->register($invoice, $this->body($invoice, ['Tornillos' => 1000], ['issue_date' => now()->addDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('issue_date');
        $this->register($invoice, $this->body($invoice, ['Tornillos' => 10.5]))
            ->assertStatus(422)->assertJsonValidationErrors('items.0.amount');
        $this->register($invoice, $this->body($invoice, [], ['number' => 'NC 01']))
            ->assertStatus(422)->assertJsonValidationErrors(['number', 'items']);

        $first = $this->register($invoice, $this->body($invoice, ['Tornillos' => 1000]))->assertCreated()->json('data');
        $this->register($invoice, $this->body($invoice, ['Tornillos' => 1000], ['number' => $first['number']]))
            ->assertStatus(409)->assertJsonPath('error_code', 'CREDIT_NOTE_DUPLICATE_NUMBER');

        $this->get("/api/invoices/{$invoice['id']}/pdf")->assertOk();
    }

    public function test_the_sales_report_shows_the_credit_notes(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->issuedInvoice();
        $this->register($invoice, $this->body($invoice, ['Cambio de embrague' => 110000]))->assertCreated();

        $this->getJson('/api/reports/sales')
            ->assertOk()
            ->assertJsonPath('data.totals.total', $invoice['totals']['total'])
            ->assertJsonPath('data.credit_notes.credit_notes_count', 1)
            ->assertJsonPath('data.credit_notes.total', '110000.00')
            ->assertJsonPath('data.credit_notes.iva_10', '10000.00')
            ->assertJsonPath('data.net_total', bcsub($invoice['totals']['total'], '110000', 2));
        $this->get('/api/reports/sales?format=csv')->assertOk();
    }

    public function test_permissions_by_role(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->issuedInvoice();
        $id = $this->register($invoice, $this->body($invoice, ['Tornillos' => 1000]))->assertCreated()->json('data.id');

        $this->actingAsRole('recepcionista');
        $this->getJson("/api/invoices/{$invoice['id']}/credit-notes")->assertOk();
        $this->getJson("/api/invoices/{$invoice['id']}/credit-notes/{$id}")->assertOk();
        $this->register($invoice, $this->body($invoice, ['Tornillos' => 1000]))->assertForbidden();
        $this->postJson("/api/invoices/{$invoice['id']}/credit-notes/{$id}/cancel", ['reason' => 'No.'])->assertForbidden();

        $this->actingAsMechanic();
        $this->getJson("/api/invoices/{$invoice['id']}/credit-notes")->assertForbidden();

        $this->actingAsRole('cajero');
        $this->register($invoice, $this->body($invoice, ['Tornillos' => 1000]))->assertCreated();
        $this->postJson("/api/invoices/{$invoice['id']}/credit-notes/{$id}/cancel", ['reason' => 'Mal cargada.'])->assertOk();
    }
}

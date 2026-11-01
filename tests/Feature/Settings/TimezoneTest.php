<?php

namespace Tests\Feature\Settings;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Invoices\InvoiceTestCase;

/**
 * El taller trabaja en hora de Paraguay (UTC-3): a las 22:00 sigue siendo el
 * mismo día, aunque en UTC ya sea el siguiente.
 */
class TimezoneTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->actingAsAdmin();
    }

    /**
     * El instante va en UTC: en la zona de la aplicación, "22:00" no probaría nada.
     */
    private function travelToUtc(string $moment): void
    {
        $this->travelTo(Carbon::parse($moment, 'UTC')->setTimezone(config('app.timezone')));
    }

    private function issuedInvoice(): array
    {
        $invoice = $this->readyInvoice();
        $this->createStamp()->assertCreated();

        return $this->postJson("/api/invoices/{$invoice['id']}/issue")->assertOk()->json('data');
    }

    public function test_the_application_runs_in_paraguay_time_and_the_api_sends_utc(): void
    {
        $this->assertSame('America/Asuncion', config('app.timezone'));
        $this->assertSame('America/Asuncion', date_default_timezone_get());

        $this->travelToUtc('2026-11-03 01:00:00');

        $this->assertSame('2026-11-02 22:00:00', now()->toDateTimeString());
        $this->assertSame('2026-11-03T01:00:00.000000Z', now()->toJSON());
    }

    public function test_an_invoice_issued_at_night_keeps_the_local_date_and_accepts_its_payment(): void
    {
        $this->travelToUtc('2026-11-03 01:00:00');

        $invoice = $this->issuedInvoice();

        $this->assertSame('2026-11-02', $invoice['issue_date']);

        $url = "/api/invoices/{$invoice['id']}/payments";

        $this->postJson($url, ['amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-11-03'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_date');

        $this->postJson($url, ['amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-11-02'])->assertCreated();
    }

    public function test_the_last_night_of_the_month_stays_in_that_month(): void
    {
        $this->travelToUtc('2026-12-01 01:00:00');

        $this->issuedInvoice();

        $period = $this->getJson('/api/reports/sales')->assertOk()->json('data.by_period.0.period');

        $this->assertStringStartsWith('2026-11', $period);
    }
}

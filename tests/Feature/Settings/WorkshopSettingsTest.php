<?php

namespace Tests\Feature\Settings;

use App\Mail\ClientNotificationMail;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Mockery;
use Tests\Feature\Invoices\InvoiceTestCase;

class WorkshopSettingsTest extends InvoiceTestCase
{
    private function patchSettings(array $data)
    {
        return $this->patchJson('/api/system-settings/workshop', $data);
    }

    public function test_without_saved_values_it_shows_the_env_defaults(): void
    {
        config(['company.name' => 'Taller del .env', 'mail.from.address' => 'env@mail.com.py']);
        $this->actingAsAdmin();

        $this->getJson('/api/system-settings/workshop')
            ->assertOk()
            ->assertJsonPath('data.company_name', 'Taller del .env')
            ->assertJsonPath('data.mail_from_address', 'env@mail.com.py')
            ->assertJsonPath('data.default_tax_rate', 10)
            ->assertJsonPath('data.reminders_at', '08:00')
            ->assertJsonPath('data.invoice_due_soon_days', 3)
            ->assertJsonPath('data.invoice_overdue_repeat_days', 7);

        $this->assertSame(0, SystemSetting::count());
    }

    public function test_saved_values_replace_the_defaults_in_every_request(): void
    {
        $this->actingAsAdmin();

        $this->patchSettings([
            'company_legal_name' => 'Taller Central S.A.',
            'company_tax_id' => '80000001-1',
            'mail_from_name' => 'Taller Central',
            'invoice_due_soon_days' => 5,
        ])->assertOk()
            ->assertJsonPath('data.company_legal_name', 'Taller Central S.A.')
            ->assertJsonPath('data.invoice_due_soon_days', 5)
            ->assertJsonPath('meta', null);

        // Otro request, como si fuera otro proceso: los datos salen de la base.
        config(['company.legal_name' => null, 'notifications.invoice_due_soon_days' => 3]);

        $this->getJson('/api/branding')->assertOk();

        $this->assertSame('Taller Central S.A.', config('company.legal_name'));
        $this->assertSame(5, config('notifications.invoice_due_soon_days'));
    }

    public function test_an_empty_value_returns_to_the_env_default(): void
    {
        config(['company.city' => 'Ciudad del .env']);
        $this->actingAsAdmin();

        $this->patchSettings(['company_city' => 'Luque'])->assertJsonPath('data.company_city', 'Luque');
        $this->patchSettings(['company_city' => null])->assertJsonPath('data.company_city', 'Ciudad del .env');

        $this->assertFalse(SystemSetting::where('key', 'company.city')->exists());
    }

    public function test_a_zero_tax_rate_is_saved_and_not_taken_as_empty(): void
    {
        $this->actingAsAdmin();

        $this->patchSettings(['default_tax_rate' => 0])
            ->assertOk()
            ->assertJsonPath('data.default_tax_rate', 0);

        $this->assertSame('0', SystemSetting::get('invoice.default_tax_rate'));
    }

    public function test_the_tax_rate_applies_to_new_documents_and_warns_only_when_it_changes(): void
    {
        $this->actingAsAdmin();

        $this->patchSettings(['default_tax_rate' => 10])->assertOk()->assertJsonPath('meta', null);

        $this->patchSettings(['default_tax_rate' => 5])
            ->assertOk()
            ->assertJsonCount(1, 'meta.warnings');

        $invoice = $this->generateInvoice($this->completedWorkOrder());

        $this->assertSame(5, $invoice['items'][0]['tax_rate']['value']);
    }

    public function test_the_budget_pdf_shows_the_rate_it_was_calculated_with(): void
    {
        $this->actingAsAdmin();
        Mail::fake();

        $rates = [];
        View::composer('pdf.budget', function ($view) use (&$rates) {
            $rates[] = $view->getData()['taxRate'];
        });

        $items = [['type' => 'labor', 'description' => 'Mano de obra', 'quantity' => 1, 'unit_price' => 1000]];

        $before = $this->createBudgetWithItems($items);
        $this->approveBudget($before);

        $this->patchSettings(['default_tax_rate' => 5])->assertOk();

        $after = $this->createBudgetWithItems($items);
        $this->approveBudget($after);

        $rates = [];
        $this->get("/api/budgets/{$before}/pdf")->assertOk();
        $this->get("/api/budgets/{$after}/pdf")->assertOk();

        $this->assertSame([10, 5], $rates);
    }

    public function test_the_defaults_keep_the_same_totals(): void
    {
        $this->actingAsAdmin();

        $invoice = $this->generateInvoice($this->completedWorkOrder());

        $this->assertSame(10, $invoice['items'][0]['tax_rate']['value']);
    }

    public function test_it_rejects_invalid_values(): void
    {
        $this->actingAsAdmin();

        $this->patchSettings([])->assertUnprocessable()->assertJsonValidationErrors('settings');
        $this->patchSettings(['unknown' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('settings');
        $this->patchSettings(['default_tax_rate' => 7])->assertUnprocessable()->assertJsonValidationErrors('default_tax_rate');
        $this->patchSettings(['mail_from_address' => 'no-es-un-correo'])->assertUnprocessable()->assertJsonValidationErrors('mail_from_address');
        $this->patchSettings(['company_email' => 'no-es-un-correo'])->assertUnprocessable()->assertJsonValidationErrors('company_email');
        $this->patchSettings(['reminders_at' => '25:00'])->assertUnprocessable()->assertJsonValidationErrors('reminders_at');
        $this->patchSettings(['invoice_overdue_repeat_days' => 0])->assertUnprocessable()->assertJsonValidationErrors('invoice_overdue_repeat_days');

        $this->assertSame(0, SystemSetting::count());
    }

    public function test_only_the_administrator_can_see_or_change_it(): void
    {
        foreach (['recepcionista', 'cajero', 'jefe-taller', 'deposito', 'mechanic'] as $role) {
            $this->actingAsRole($role);

            $this->getJson('/api/system-settings/workshop')->assertForbidden();
            $this->patchSettings(['company_name' => 'Otro'])->assertForbidden();
        }

        $this->assertSame(0, SystemSetting::count());
    }

    public function test_changes_are_audited(): void
    {
        $this->actingAsAdmin();

        $this->patchSettings(['default_tax_rate' => 5])->assertOk();

        $this->assertTrue(AuditLog::where('auditable_type', 'system_setting')->exists());
    }

    public function test_the_notification_email_uses_the_saved_sender(): void
    {
        $this->actingAsAdmin();

        $this->patchSettings(['mail_from_name' => 'Taller Central', 'mail_from_address' => 'avisos@mail.com.py'])->assertOk();

        $from = (new ClientNotificationMail(['subject' => 'Aviso', 'greeting' => 'Hola', 'lines' => [], 'rows' => []]))->envelope()->from;

        $this->assertSame('avisos@mail.com.py', $from->address);
        $this->assertSame('Taller Central', $from->name);
    }

    public function test_a_queued_job_sees_the_current_settings_also_after_they_are_removed(): void
    {
        config(['mail.from.name' => 'Remitente del .env']);
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);

        SystemSetting::create(['key' => 'mail.from.name', 'value' => 'Taller Central']);
        Event::dispatch(new JobProcessing('sync', $job));
        $this->assertSame('Taller Central', config('mail.from.name'));

        SystemSetting::where('key', 'mail.from.name')->first()->delete();
        Event::dispatch(new JobProcessing('sync', $job));
        $this->assertSame('Remitente del .env', config('mail.from.name'));
    }

    public function test_the_reminders_command_uses_the_saved_settings(): void
    {
        SystemSetting::create(['key' => 'notifications.invoice_due_soon_days', 'value' => '9']);

        $this->artisan('notifications:send-reminders')->assertSuccessful();

        $this->assertSame(9, config('notifications.invoice_due_soon_days'));
    }

    public function test_the_reminders_hour_is_local_and_read_when_the_scheduler_runs(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'notifications:send-reminders'));

        // Paraguay está en UTC-3: las 08:00 son las 11:00 UTC.
        $this->travelTo('2026-11-02 11:00:00');
        $this->assertTrue($event->filtersPass(app()));

        $this->travelTo('2026-11-02 08:00:00');
        $this->assertFalse($event->filtersPass(app()));

        SystemSetting::create(['key' => 'notifications.reminders_at', 'value' => '05:00']);
        $this->assertTrue($event->filtersPass(app()));
    }
}

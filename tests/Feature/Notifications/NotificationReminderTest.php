<?php

namespace Tests\Feature\Notifications;

use App\Enums\ClientNotificationType;
use App\Models\Brand;
use App\Models\Client;
use App\Models\ClientNotification;
use App\Models\Invoice;
use App\Models\MaintenancePlan;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\ClientNotificationService;
use App\Services\MaintenanceService;
use App\Services\NotificationReminderService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Invoices\InvoiceTestCase;

class NotificationReminderTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function reminders(): NotificationReminderService
    {
        return app(NotificationReminderService::class);
    }

    private function notificationsOf(ClientNotificationType $type): int
    {
        return ClientNotification::where('type', $type)->count();
    }

    /**
     * Factura a crédito emitida que vence en $days días; el cliente tiene email.
     */
    private function creditInvoice(int $days, array $overrides = []): array
    {
        $invoice = $this->readyInvoice();
        $this->createStamp()->assertCreated();

        $this->patchJson("/api/invoices/{$invoice['id']}", [
            'condition' => 'credit',
            'due_date' => now()->addDays($days)->toDateString(),
            ...$overrides,
        ])->assertOk();

        $invoice = $this->postJson("/api/invoices/{$invoice['id']}/issue")->assertOk()->json('data');

        Invoice::findOrFail($invoice['id'])->client->update(['email' => 'ana@example.com']);

        return $invoice;
    }

    public function test_credit_invoice_reminders_before_and_after_due_date(): void
    {
        $this->actingAsAdmin();

        $invoice = $this->creditInvoice(2);

        $this->assertSame(1, $this->reminders()->invoiceReminders());
        $this->assertSame(0, $this->reminders()->invoiceReminders());
        $this->assertSame(1, $this->notificationsOf(ClientNotificationType::INVOICE_DUE_SOON));

        // Vencida hace 1 día: primer aviso; hasta cumplir 7 días no se repite.
        $this->travel(3)->days();
        $this->assertSame(1, $this->reminders()->invoiceReminders());
        $this->travel(6)->days();
        $this->assertSame(0, $this->reminders()->invoiceReminders());
        $this->travel(1)->days();
        $this->assertSame(1, $this->reminders()->invoiceReminders());
        $this->assertSame(2, $this->notificationsOf(ClientNotificationType::INVOICE_OVERDUE));

        $mail = ClientNotification::where('type', ClientNotificationType::INVOICE_OVERDUE)->first();
        $content = app(ClientNotificationService::class)->content($mail);
        $this->assertSame("Factura {$invoice['number']} vencida", $content['subject']);

        // Pagada: sin avisos.
        $this->postJson("/api/invoices/{$invoice['id']}/payments", [
            'amount' => (int) Invoice::findOrFail($invoice['id'])->total,
            'method' => 'transfer',
            'reference' => 'TR-1',
            'bank' => 'Itaú',
        ])->assertCreated();
        $this->travel(7)->days();
        $this->assertSame(0, $this->reminders()->invoiceReminders());
    }

    public function test_invoice_addressed_to_someone_else_is_not_reminded_to_the_client(): void
    {
        $this->actingAsAdmin();

        $invoice = $this->creditInvoice(2, [
            'customer_name' => 'Seguros del Sur S.A.',
            'customer_tax_id' => '80012345-6',
        ]);
        Invoice::findOrFail($invoice['id'])->client->update(['document_number' => '1234567']);
        // Desde D8 el cliente tiene email: los avisos previos (presupuesto, vehículo listo) se envían; acá solo cuenta el recordatorio.
        Mail::fake();

        $this->assertSame(1, $this->reminders()->invoiceReminders());
        $this->assertSame(0, $this->reminders()->invoiceReminders());

        $notification = ClientNotification::where('type', ClientNotificationType::INVOICE_DUE_SOON)->sole();
        $this->assertSame('skipped', $notification->status->value);
        $this->assertSame('La factura está a nombre de otro destinatario.', $notification->error);
        $this->assertSame($invoice['id'], $notification->subject_id);
        Mail::assertNothingSent();

        // Vencida: también omitida.
        $this->travel(3)->days();
        $this->assertSame(1, $this->reminders()->invoiceReminders());
        $this->assertSame('skipped', ClientNotification::where('type', ClientNotificationType::INVOICE_OVERDUE)->sole()->status->value);
        Mail::assertNothingSent();

        // El reenvío manual sigue disponible y va al cliente.
        $this->postJson("/api/client-notifications/{$notification->id}/resend")
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'sent');
    }

    public function test_ruc_of_the_same_client_still_reminds_the_client(): void
    {
        $this->actingAsAdmin();

        $invoice = $this->readyInvoice();
        $client = Invoice::findOrFail($invoice['id'])->client;
        $client->update(['document_number' => '1234567']);

        $this->createStamp()->assertCreated();
        $this->patchJson("/api/invoices/{$invoice['id']}", [
            'condition' => 'credit',
            'due_date' => now()->addDays(2)->toDateString(),
            'customer_tax_id' => '1234567-8',
        ])->assertOk();
        $this->postJson("/api/invoices/{$invoice['id']}/issue")->assertOk();
        $client->update(['email' => 'ana@example.com']);

        $this->assertSame(1, $this->reminders()->invoiceReminders());
        $this->assertSame('sent', ClientNotification::where('type', ClientNotificationType::INVOICE_DUE_SOON)->sole()->status->value);
    }

    public function test_invoice_far_from_due_date_is_not_reminded(): void
    {
        $this->actingAsAdmin();

        $this->creditInvoice(30);

        $this->assertSame(0, $this->reminders()->invoiceReminders());
    }

    public function test_maintenance_reminders_once_per_status(): void
    {
        $this->actingAsAdmin();

        $brand = Brand::create(['name' => 'Marca ' . Str::random(6)]);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Modelo ' . Str::random(6)]);
        $client = Client::create(['document_number' => 'QA-6001', 'first_name' => 'Ana', 'last_name' => 'Gomez', 'phone' => '0981123456', 'email' => 'ana@example.com']);
        $vehicle = Vehicle::create([
            'client_id' => $client->id,
            'brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'plate' => 'ABC123',
        ]);
        $plan = MaintenancePlan::create(['name' => 'Cambio de aceite', 'interval_days' => 30, 'is_active' => true]);

        app(MaintenanceService::class)->recordDone($vehicle, $plan, ['done_at' => now()->subDays(20)->toDateString()]);

        $this->assertSame(1, $this->reminders()->maintenanceReminders());
        $this->assertSame(0, $this->reminders()->maintenanceReminders());

        $this->travel(11)->days();
        $this->assertSame(1, $this->reminders()->maintenanceReminders());
        $this->assertSame(0, $this->reminders()->maintenanceReminders());

        $this->assertSame(1, $this->notificationsOf(ClientNotificationType::MAINTENANCE_DUE_SOON));
        $this->assertSame(1, $this->notificationsOf(ClientNotificationType::MAINTENANCE_OVERDUE));

        $overdue = ClientNotification::where('type', ClientNotificationType::MAINTENANCE_OVERDUE)->sole();
        $this->assertSame('vehicle_maintenance', $overdue->subject_type);
        $this->assertSame('sent', $overdue->status->value);
        $this->assertSame('Mantenimiento vencido: Cambio de aceite', app(ClientNotificationService::class)->content($overdue)['subject']);
    }

    public function test_overdue_accounts_payable_notify_cashiers_once_a_day(): void
    {
        $cashier = User::create([
            'name' => 'cajero_' . Str::random(6),
            'first_name' => 'Test',
            'last_name' => 'Cajero',
            'email' => Str::random(10) . '@test.local',
            'password' => bcrypt('password'),
        ])->assignRole('cajero');

        $this->actingAsAdmin();

        $this->assertSame(0, $this->reminders()->accountsPayableReminder());

        $part = $this->createPartViaApi();
        $this->purchase($part['id'], 1, 150000, ['supplier_document_date' => now()->subDay()->toDateString()])->assertOk();

        $this->assertSame(1, $this->reminders()->accountsPayableReminder());
        $this->assertSame(0, $this->reminders()->accountsPayableReminder());

        $notification = $cashier->notifications()->sole();
        $this->assertSame('accounts_payable_overdue', $notification->type);
        $this->assertStringContainsString('Gs. 150.000', $notification->data['message']);

        $this->travel(1)->days();
        $this->assertSame(1, $this->reminders()->accountsPayableReminder());
    }

    public function test_command_runs_all_reminders(): void
    {
        $this->artisan('notifications:send-reminders')
            ->expectsOutput('Facturas: 0 aviso(s).')
            ->assertSuccessful();
    }
}

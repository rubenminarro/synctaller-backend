<?php

namespace Tests\Feature\Notifications;

use App\Enums\ClientNotificationStatus;
use App\Enums\ClientNotificationType;
use App\Mail\ClientNotificationMail;
use App\Models\Budget;
use App\Models\Client;
use App\Models\ClientNotification;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Feature\Invoices\InvoiceTestCase;

class ClientNotificationTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function clientOf(int $budgetId): Client
    {
        return Budget::findOrFail($budgetId)->reception->client;
    }

    /** Email vacío (''): el cliente "sin email"; la base ya no admite null (D8) y el control queda como defensa. */
    private function draftBudget(string $email = 'ana@example.com'): int
    {
        $budgetId = $this->createBudgetWithItems([
            ['type' => 'labor', 'description' => 'Cambio de embrague', 'quantity' => 1, 'unit_price' => 200000],
        ]);

        $this->clientOf($budgetId)->update(['email' => $email]);

        return $budgetId;
    }

    public function test_sending_a_budget_emails_the_client(): void
    {
        $this->actingAsAdmin();

        $budgetId = $this->draftBudget();

        $this->postJson("/api/budgets/{$budgetId}/send")->assertOk();

        $notification = ClientNotification::sole();
        $this->assertSame(ClientNotificationType::BUDGET_SENT, $notification->type);
        $this->assertSame(ClientNotificationStatus::SENT, $notification->status);
        $this->assertSame('ana@example.com', $notification->recipient);
        $this->assertSame(1, $notification->attempts);
        $this->assertNotNull($notification->sent_at);

        Mail::assertSent(ClientNotificationMail::class, function (ClientNotificationMail $mail) {
            return $mail->hasTo('ana@example.com')
                && $mail->content['subject'] === 'Presupuesto BUD-000001'
                && str_contains($mail->content['lines'][1], 'Total: Gs. 220.000')
                && $mail->content['rows'][0]['total'] === 'Gs. 200.000';
        });

        // Reabrir y volver a enviar genera otro aviso.
        $this->postJson("/api/budgets/{$budgetId}/reject")->assertOk();
        $this->postJson("/api/budgets/{$budgetId}/reopen")->assertOk();
        $this->postJson("/api/budgets/{$budgetId}/send")->assertOk();

        $this->assertSame(2, ClientNotification::count());
    }

    public function test_client_without_email_or_opted_out_is_skipped(): void
    {
        $this->actingAsAdmin();

        $withoutEmail = $this->draftBudget('');
        $this->postJson("/api/budgets/{$withoutEmail}/send")->assertOk();

        $optedOut = $this->draftBudget('luis@example.com');
        $this->patchJson("/api/clients/{$this->clientOf($optedOut)->id}", ['notifications_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.notifications_enabled', false);
        $this->postJson("/api/budgets/{$optedOut}/send")->assertOk();

        Mail::assertNothingSent();

        $notifications = ClientNotification::orderBy('id')->get();
        $this->assertSame([ClientNotificationStatus::SKIPPED, ClientNotificationStatus::SKIPPED], $notifications->pluck('status')->all());
        $this->assertSame('El cliente no tiene email registrado.', $notifications[0]->error);
        $this->assertSame('El cliente desactivó los avisos.', $notifications[1]->error);
    }

    public function test_delivery_failure_is_recorded_without_breaking_the_operation(): void
    {
        $this->actingAsAdmin();

        $budgetId = $this->draftBudget();

        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP no disponible'));

        $this->postJson("/api/budgets/{$budgetId}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $notification = ClientNotification::sole();
        $this->assertSame(ClientNotificationStatus::FAILED, $notification->status);
        $this->assertSame('SMTP no disponible', $notification->error);
        $this->assertNull($notification->sent_at);
    }

    public function test_vehicle_ready_is_notified_once_per_reception(): void
    {
        $this->actingAsAdmin();

        $workOrder = $this->completedWorkOrder();

        $ready = ClientNotification::where('type', ClientNotificationType::VEHICLE_READY)->get();
        $this->assertCount(1, $ready);
        $this->assertSame('reception', $ready[0]->subject_type);
        $this->assertSame($workOrder['reception']['id'], $ready[0]->subject_id);
        $this->assertSame("vehicle_ready:{$workOrder['reception']['id']}", $ready[0]->dedupe_key);
    }

    public function test_resend_rules(): void
    {
        $user = $this->actingAsAdmin();

        $budgetId = $this->draftBudget('');
        $this->postJson("/api/budgets/{$budgetId}/send")->assertOk();
        $skipped = ClientNotification::sole();

        $resend = "/api/client-notifications/{$skipped->id}/resend";

        $this->postJson($resend)->assertStatus(422)->assertJsonPath('error_code', 'CLIENT_WITHOUT_EMAIL');

        $client = $this->clientOf($budgetId);
        $client->update(['email' => 'ana@example.com', 'notifications_enabled' => false]);
        $this->postJson($resend)->assertStatus(422)->assertJsonPath('error_code', 'CLIENT_NOTIFICATIONS_DISABLED');

        $client->update(['notifications_enabled' => true]);
        $this->postJson($resend)
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'sent')
            ->assertJsonPath('data.resent_from_id', $skipped->id)
            ->assertJsonPath('data.recipient', 'ana@example.com')
            ->assertJsonPath('data.subject.code', 'BUD-000001')
            ->assertJsonPath('data.created_by.id', $user->id);

        Mail::assertSent(ClientNotificationMail::class, 1);

        // En cola: no se reenvía.
        $pending = ClientNotification::create([
            ...$skipped->only(['client_id', 'type', 'channel', 'subject_type', 'subject_id']),
            'status' => ClientNotificationStatus::PENDING,
        ]);
        $this->postJson("/api/client-notifications/{$pending->id}/resend")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'CLIENT_NOTIFICATION_PENDING');

        // Documento eliminado.
        Budget::findOrFail($budgetId)->delete();
        $this->postJson($resend)->assertStatus(422)->assertJsonPath('error_code', 'CLIENT_NOTIFICATION_SUBJECT_UNAVAILABLE');
    }

    public function test_listing_filters_and_permissions(): void
    {
        $this->actingAsAdmin();

        $first = $this->draftBudget();
        $second = $this->draftBudget('');
        $this->postJson("/api/budgets/{$first}/send")->assertOk();
        $this->postJson("/api/budgets/{$second}/send")->assertOk();

        $this->actingAsRole('recepcionista');

        $this->getJson('/api/client-notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 2);

        $this->getJson('/api/client-notifications?status=skipped')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type.value', 'budget_sent')
            ->assertJsonPath('data.0.client_name', 'Ana Gomez');

        $client = $this->clientOf($first);
        $this->getJson("/api/clients/{$client->id}/notifications")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status.value', 'sent');

        $id = ClientNotification::first()->id;
        $this->getJson("/api/client-notifications/{$id}")
            ->assertOk()
            ->assertJsonPath('data.client.notifications_enabled', true)
            ->assertJsonPath('data.channel.value', 'mail');

        $this->getJson('/api/client-notifications?type=x')->assertStatus(422)->assertJsonValidationErrors(['type']);

        $this->actingAsRole('cajero');
        $this->getJson('/api/client-notifications')->assertOk();

        $this->actingAsMechanic();
        $this->getJson('/api/client-notifications')->assertForbidden();
        $this->postJson("/api/client-notifications/{$id}/resend")->assertForbidden();
    }
}

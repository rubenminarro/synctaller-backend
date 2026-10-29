<?php

namespace App\Services;

use App\Enums\ClientNotificationStatus;
use App\Enums\ClientNotificationType;
use App\Enums\NotificationChannel;
use App\Exceptions\NotificationException;
use App\Jobs\SendClientNotification;
use App\Mail\ClientNotificationMail;
use App\Models\Budget;
use App\Models\Client;
use App\Models\ClientNotification;
use App\Models\Invoice;
use App\Models\Reception;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Avisos a clientes (FASE 23). Cada aviso queda registrado; el envío va a la
 * cola después del commit, así un rollback no deja correos enviados. Sin
 * email o con los avisos desactivados el aviso queda omitido (skipped).
 */
class ClientNotificationService
{
    public function __construct(
        protected DocumentPdfService $documentPdfService
    ) {
    }

    /**
     * Registra un aviso automático. Con clave de deduplicación, un aviso ya
     * registrado no se repite (devuelve null). Con $skipReason el aviso queda
     * registrado como omitido con ese motivo, sin envío.
     */
    public function notify(
        ClientNotificationType $type,
        Model $subject,
        ?Client $client,
        ?string $dedupeKey = null,
        ?string $skipReason = null
    ): ?ClientNotification {
        // Cliente eliminado: no se avisa.
        if ($client === null || $client->trashed()) {
            return null;
        }

        if ($dedupeKey !== null && ClientNotification::where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        return $this->register($type, $subject, $client, ['dedupe_key' => $dedupeKey], $skipReason);
    }

    /**
     * Reenvío manual: crea otro registro con el contenido actual del documento.
     */
    public function resend(ClientNotification $notification, User $user): ClientNotification
    {
        return DB::transaction(function () use ($notification, $user) {

            $notification = ClientNotification::whereKey($notification->id)->lockForUpdate()->firstOrFail();

            if ($notification->status === ClientNotificationStatus::PENDING) {
                throw new NotificationException(
                    'El aviso todavía está en cola de envío.',
                    409,
                    'CLIENT_NOTIFICATION_PENDING'
                );
            }

            $client = $notification->client;
            $subject = $notification->subject;

            if ($subject === null || $client->trashed()) {
                throw new NotificationException(
                    'El aviso no puede reenviarse: el cliente o el documento fueron eliminados.',
                    422,
                    'CLIENT_NOTIFICATION_SUBJECT_UNAVAILABLE'
                );
            }

            if (!$client->notifications_enabled) {
                throw new NotificationException(
                    'El cliente desactivó los avisos.',
                    422,
                    'CLIENT_NOTIFICATIONS_DISABLED'
                );
            }

            if (blank($client->email)) {
                throw new NotificationException(
                    'El cliente no tiene email registrado.',
                    422,
                    'CLIENT_WITHOUT_EMAIL'
                );
            }

            return $this->register($notification->type, $subject, $client, [
                'resent_from_id' => $notification->id,
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * Envío (SendClientNotification). Un fallo queda registrado como failed,
     * sin reintentos ni excepción hacia quien despachó.
     */
    public function deliver(int $clientNotificationId): void
    {
        $notification = ClientNotification::with(['client', 'subject'])->find($clientNotificationId);

        if ($notification === null || $notification->status !== ClientNotificationStatus::PENDING) {
            return;
        }

        try {
            Mail::to($notification->recipient)->send(new ClientNotificationMail($this->content($notification)));

            $notification->update([
                'status' => ClientNotificationStatus::SENT,
                'sent_at' => now(),
                'attempts' => $notification->attempts + 1,
                'error' => null,
            ]);
        } catch (Throwable $e) {
            report($e);

            $notification->update([
                'status' => ClientNotificationStatus::FAILED,
                'attempts' => $notification->attempts + 1,
                'error' => Str::limit($e->getMessage(), 500),
            ]);
        }
    }

    /**
     * Contenido del aviso con el estado actual del documento.
     *
     * @return array{subject: string, greeting: string, lines: string[], rows: array<int, array<string, string>>}
     */
    public function content(ClientNotification $notification): array
    {
        $subject = $notification->subject;

        if ($subject === null) {
            throw new NotificationException(
                'El documento del aviso ya no está disponible.',
                422,
                'CLIENT_NOTIFICATION_SUBJECT_UNAVAILABLE'
            );
        }

        return [
            'greeting' => "Hola, {$notification->client->greeting_name}",
            'rows' => [],
            ...match ($notification->type) {
                ClientNotificationType::BUDGET_SENT => $this->budgetContent($subject),
                ClientNotificationType::VEHICLE_READY => $this->vehicleReadyContent($subject),
                ClientNotificationType::INVOICE_DUE_SOON => $this->invoiceContent($subject, false),
                ClientNotificationType::INVOICE_OVERDUE => $this->invoiceContent($subject, true),
                ClientNotificationType::MAINTENANCE_DUE_SOON => $this->maintenanceContent($subject, false),
                ClientNotificationType::MAINTENANCE_OVERDUE => $this->maintenanceContent($subject, true),
            },
        ];
    }

    private function register(
        ClientNotificationType $type,
        Model $subject,
        Client $client,
        array $attributes,
        ?string $skipReason = null
    ): ClientNotification {
        $skipReason ??= match (true) {
            !$client->notifications_enabled => 'El cliente desactivó los avisos.',
            blank($client->email) => 'El cliente no tiene email registrado.',
            default => null,
        };

        $notification = ClientNotification::create([
            'client_id' => $client->id,
            'type' => $type,
            'channel' => NotificationChannel::MAIL,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'recipient' => $client->email,
            'status' => $skipReason ? ClientNotificationStatus::SKIPPED : ClientNotificationStatus::PENDING,
            'error' => $skipReason,
            ...$attributes,
        ]);

        if ($skipReason === null) {
            SendClientNotification::dispatch($notification->id)->afterCommit();
        }

        return $notification;
    }

    private function budgetContent(Budget $budget): array
    {
        $budget->loadMissing(['items', 'reception.vehicle.brand', 'reception.vehicle.vehicleModel']);

        return [
            'subject' => "Presupuesto {$budget->code}",
            'lines' => [
                "Le enviamos el presupuesto {$budget->code} para su vehículo {$this->vehicleLabel($budget->reception?->vehicle)}.",
                "Subtotal (sin IVA): {$this->money($budget->subtotal)}. IVA: {$this->money($budget->tax)}. Total: {$this->money($budget->total)}.",
                'Para aprobarlo o hacer consultas, comuníquese con el taller.',
            ],
            'rows' => $budget->items->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => Format::quantity($item->quantity, 2),
                'total' => $this->money($item->total),
            ])->values()->all(),
            'attachments' => [$this->documentPdfService->budget($budget)],
        ];
    }

    private function vehicleReadyContent(Reception $reception): array
    {
        $reception->loadMissing(['vehicle.brand', 'vehicle.vehicleModel']);

        return [
            'subject' => 'Su vehículo está listo',
            'lines' => [
                "Su vehículo {$this->vehicleLabel($reception->vehicle)} está listo para retirar.",
                'Lo esperamos en el taller.',
            ],
        ];
    }

    private function invoiceContent(Invoice $invoice, bool $overdue): array
    {
        $dueDate = $invoice->due_date?->format('d/m/Y');
        $balance = $this->money($invoice->balance());

        return $overdue
            ? [
                'subject' => "Factura {$invoice->number} vencida",
                'lines' => [
                    "La factura {$invoice->number} venció el {$dueDate}. Saldo pendiente: {$balance}.",
                    'Si ya realizó el pago, desestime este aviso.',
                ],
            ]
            : [
                'subject' => "Su factura {$invoice->number} vence el {$dueDate}",
                'lines' => [
                    "Le recordamos que la factura {$invoice->number} vence el {$dueDate}. Saldo pendiente: {$balance}.",
                ],
            ];
    }

    private function maintenanceContent(VehicleMaintenance $maintenance, bool $overdue): array
    {
        $maintenance->loadMissing(['plan', 'vehicle.brand', 'vehicle.vehicleModel']);

        $plan = $maintenance->plan->name;
        $due = collect([
            $maintenance->next_due_at ? 'el ' . $maintenance->next_due_at->format('d/m/Y') : null,
            $maintenance->next_due_mileage !== null ? 'a los ' . number_format($maintenance->next_due_mileage, 0, ',', '.') . ' km' : null,
        ])->filter()->implode(' o ');

        return [
            'subject' => $overdue ? "Mantenimiento vencido: {$plan}" : "Mantenimiento próximo: {$plan}",
            'lines' => [
                ($overdue
                    ? "El mantenimiento \"{$plan}\" de su vehículo {$this->vehicleLabel($maintenance->vehicle)} está vencido"
                    : "El mantenimiento \"{$plan}\" de su vehículo {$this->vehicleLabel($maintenance->vehicle)} está próximo a vencer")
                    . ($due !== '' ? " (vence {$due})." : '.'),
                'Comuníquese con el taller para agendar el servicio.',
            ],
        ];
    }

    private function vehicleLabel(?Vehicle $vehicle): string
    {
        if ($vehicle === null) {
            return '';
        }

        $name = trim("{$vehicle->brand?->name} {$vehicle->vehicleModel?->name}");
        $identifier = $vehicle->plate ?: $vehicle->chassis;

        return trim($name . ($identifier ? " ({$identifier})" : ''));
    }

    private function money(int|float|string|null $amount): string
    {
        return Format::money($amount);
    }
}

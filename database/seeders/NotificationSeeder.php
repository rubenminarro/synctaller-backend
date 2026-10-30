<?php

namespace Database\Seeders;

use App\Enums\ClientNotificationStatus;
use App\Enums\ClientNotificationType;
use App\Models\ClientNotification;
use App\Services\ClientNotificationService;
use App\Services\NotificationReminderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Avisos de ejemplo. Los registran solos los Services durante los escenarios
 * (presupuesto enviado, vehículo listo, avisos internos); acá se completan sus
 * estados:
 *
 * - A clientes: enviados, uno fallido y reenviado, omitido (cliente dado de baja:
 *   escenario C16) y pendientes (los más recientes y los que genera la tarea
 *   diaria de recordatorios).
 * - Internos: leídos los de más de tres días; los recientes, sin leer.
 *
 * Nunca sale un email: los envíos usan el mailer "array" (en memoria) y el
 * fallido, un servidor SMTP inexistente.
 */
class NotificationSeeder extends Seeder
{
    /** Los avisos de los últimos días quedan pendientes, como si la cola todavía no los hubiera procesado. */
    private const PENDING_DAYS = 2;

    public function run(ClientNotificationService $notificationService, NotificationReminderService $reminderService): void
    {
        // Primero el fallido (el aviso de presupuesto más reciente), después el envío del resto.
        $this->failAndResend($notificationService);
        $this->deliver($notificationService);

        // Tarea diaria (notifications:send-reminders): facturas vencidas, mantenimientos y cuentas por pagar. No repite avisos.
        $reminderService->run();

        // Avisos internos de más de tres días: leídos unas horas después de llegar.
        DB::table('notifications')->whereNull('read_at')->where('created_at', '<', now()->subDays(3))
            ->update(['read_at' => DB::raw(DB::getDriverName() === 'sqlite' ? "datetime(created_at, '+3 hours')" : 'DATE_ADD(created_at, INTERVAL 3 HOUR)')]);
    }

    /**
     * Envía por el Service los avisos pendientes de más de dos días. Los de vehículo listo, todos; de los de
     * presupuesto enviado (que adjuntan el PDF: ~0,35 s cada uno), los tres más recientes; los demás se marcan
     * enviados sin generar el PDF.
     */
    private function deliver(ClientNotificationService $notificationService): void
    {
        $pending = ClientNotification::where('status', ClientNotificationStatus::PENDING)
            ->where('created_at', '<', now()->subDays(self::PENDING_DAYS))
            ->orderBy('created_at')
            ->get();

        $budgetSent = $pending->where('type', ClientNotificationType::BUDGET_SENT);
        $viaService = $pending->where('type', '!=', ClientNotificationType::BUDGET_SENT)->merge($budgetSent->take(-3));

        $this->withMailer('array', function () use ($viaService, $notificationService) {
            foreach ($viaService as $notification) {
                ExampleScenarios::travel($notification->created_at->toImmutable()->addMinutes(5), fn () => $notificationService->deliver($notification->id));
            }
        });

        foreach ($budgetSent->slice(0, -3) as $notification) {
            $notification->update(['status' => ClientNotificationStatus::SENT, 'sent_at' => $notification->created_at->copy()->addMinutes(5), 'attempts' => 1]);
        }
    }

    /**
     * El aviso de presupuesto más reciente falla (servidor de correo caído) y la recepcionista lo reenvía.
     */
    private function failAndResend(ClientNotificationService $notificationService): void
    {
        // Idempotente: si ya hay un aviso fallido, ya se hizo.
        if (ClientNotification::where('status', ClientNotificationStatus::FAILED)->exists()) {
            return;
        }

        $notification = ClientNotification::where('status', ClientNotificationStatus::PENDING)
            ->where('type', ClientNotificationType::BUDGET_SENT)
            ->latest('id')
            ->first();

        if (!$notification) {
            return;
        }

        config(['mail.mailers.seeder_unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 9, 'timeout' => 0.5]]);

        $this->withMailer('seeder_unreachable', fn () => $notificationService->deliver($notification->id));

        $receptionist = ExampleScenarios::user('recepcion1@mail.com.py');
        $notificationService->resend($notification->refresh(), $receptionist);
    }

    private function withMailer(string $mailer, callable $callback): void
    {
        $previous = config('mail.default');
        config(['mail.default' => $mailer]);
        app('mail.manager')->forgetMailers();

        try {
            $callback();
        } finally {
            config(['mail.default' => $previous]);
            app('mail.manager')->forgetMailers();
        }
    }
}

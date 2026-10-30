<?php

namespace App\Services;

use App\Enums\ClientNotificationType;
use App\Enums\InvoiceCondition;
use App\Enums\InvoiceStatus;
use App\Enums\MaintenanceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\VehicleMaintenance;

/**
 * Recordatorios de la tarea diaria (notifications:send-reminders). Las claves
 * de deduplicación permiten correrla más de una vez por día sin repetir avisos.
 */
class NotificationReminderService
{
    public function __construct(
        protected ClientNotificationService $clientNotifications,
        protected InternalNotificationService $internalNotifications,
        protected MaintenanceService $maintenanceService,
        protected SupplierPaymentService $supplierPaymentService
    ) {
    }

    /**
     * @return array{invoices: int, maintenances: int, accounts_payable: int}
     */
    public function run(): array
    {
        return [
            'invoices' => $this->invoiceReminders(),
            'maintenances' => $this->maintenanceReminders(),
            'accounts_payable' => $this->accountsPayableReminder(),
        ];
    }

    /**
     * Facturas a crédito con saldo: un aviso dentro de los días previos al
     * vencimiento y, ya vencidas, uno por cada período de repetición.
     */
    public function invoiceReminders(): int
    {
        $today = today();
        $repeat = max(1, (int) config('notifications.invoice_overdue_repeat_days', 7));
        $limit = $today->copy()->addDays((int) config('notifications.invoice_due_soon_days', 3));

        return Invoice::query()
            ->where('status', InvoiceStatus::ISSUED)
            ->where('condition', InvoiceCondition::CREDIT)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $limit->toDateString())
            ->whereIn('client_id', Client::select('id'))
            ->withSum(['payments as paid_total' => fn ($q) => $q->registered()], 'amount')
            ->withSum(['creditNotes as credited_total' => fn ($q) => $q->registered()], 'total')
            ->with('client')
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice) => bccomp($invoice->balance(), '0', 2) > 0)
            ->filter(function (Invoice $invoice) use ($today, $repeat) {
                $days = (int) $today->diffInDays($invoice->due_date, false);

                [$type, $key] = $days >= 0
                    ? [ClientNotificationType::INVOICE_DUE_SOON, "invoice_due_soon:{$invoice->id}"]
                    : [ClientNotificationType::INVOICE_OVERDUE, "invoice_overdue:{$invoice->id}:" . intdiv(-$days - 1, $repeat)];

                $skipReason = $this->isAddressedToClient($invoice) ? null : 'La factura está a nombre de otro destinatario.';

                return $this->clientNotifications->notify($type, $invoice, $invoice->client, $key, $skipReason) !== null;
            })
            ->count();
    }

    /**
     * Si la factura sigue a nombre del cliente. Su RUC o CI pudo editarse en el
     * borrador (por ejemplo, para facturar a una aseguradora): entonces el
     * recordatorio no es para el cliente. CI y RUC de la misma persona cuentan
     * como el mismo documento (se ignora el dígito verificador). Sin documento
     * del cliente no hay con qué comparar y se avisa como siempre.
     */
    private function isAddressedToClient(Invoice $invoice): bool
    {
        $clientDocument = $invoice->client->document_number;

        if (blank($clientDocument)) {
            return true;
        }

        return $this->normalizeDocument($invoice->customer_tax_id) === $this->normalizeDocument($clientDocument);
    }

    private function normalizeDocument(?string $document): string
    {
        $document = strtoupper(preg_replace('/\s+/', '', (string) $document));

        return preg_replace('/-\d$/', '', $document);
    }

    /**
     * Un aviso al pasar a "por vencer" y otro al vencer, por cada ciclo del
     * mantenimiento (la clave incluye la fecha del último realizado).
     */
    public function maintenanceReminders(): int
    {
        return $this->maintenanceService->alerts(null)
            ->filter(fn (array $alert) => $alert['client'] !== null)
            ->filter(function (array $alert) {
                $maintenance = VehicleMaintenance::where('vehicle_id', $alert['vehicle']['id'])
                    ->where('maintenance_plan_id', $alert['maintenance_plan_id'])
                    ->firstOrFail();

                $type = $alert['status']['value'] === MaintenanceStatus::OVERDUE->value
                    ? ClientNotificationType::MAINTENANCE_OVERDUE
                    : ClientNotificationType::MAINTENANCE_DUE_SOON;

                return $this->clientNotifications->notify(
                    $type,
                    $maintenance,
                    Client::find($alert['client']['id']),
                    "{$type->value}:{$maintenance->id}:{$alert['last_done_at']}"
                ) !== null;
            })
            ->count();
    }

    /**
     * Aviso interno con el saldo vencido a proveedores (si hay).
     */
    public function accountsPayableReminder(): int
    {
        $payable = $this->supplierPaymentService->accountsPayable();

        if (bccomp($payable['overdue'], '0', 2) <= 0) {
            return 0;
        }

        $suppliers = collect($payable['suppliers'])->filter(fn (array $s) => bccomp($s['overdue'], '0', 2) > 0)->count();

        return $this->internalNotifications->accountsPayableOverdue($payable['overdue'], $suppliers);
    }
}

<?php

namespace App\Services;

use App\Enums\AllocationPortionType;
use App\Enums\BudgetItemResponsibility;
use App\Enums\BudgetStatus;
use App\Enums\ClaimStatus;
use App\Enums\InsurerAuthorizationStatus;
use App\Enums\InvoiceRecipientType;
use App\Enums\InvoiceStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Budget;
use App\Models\Claim;
use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Models\WorkOrderAllocation;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Resumen económico de un siniestro (M11): de lo presupuestado a lo cobrado, sobre
 * todos los presupuestos y OT de su recepción (también los complementarios).
 *
 *   budgets     presupuestado, autorizado y aceptado por el dueño (sin IVA)
 *   allocations repartos confirmados del siniestro (sin IVA)
 *   billing     facturas vigentes: facturado neto de notas de crédito, cobrado y saldo (con IVA)
 *
 * Los dos primeros bloques van sin IVA y el tercero con IVA: no se mezclan. Cada
 * bloque se arma solo si quien consulta puede verlo. Solo lectura.
 */
class ClaimSummaryService
{
    public const BUDGETS = 'budgets';
    public const ALLOCATIONS = 'allocations';
    public const BILLING = 'billing';

    public function __construct(
        protected OwnerShareService $ownerShare,
        protected AllocationBillingService $allocationBilling
    ) {
    }

    /**
     * @param string[] $blocks bloques visibles (BUDGETS, ALLOCATIONS, BILLING)
     */
    public function summary(Claim $claim, array $blocks): array
    {
        $claim->loadMissing(['insurer.client', 'reception.vehicle', 'reception.client']);
        $workOrders = WorkOrder::where('reception_id', $claim->reception_id)
            ->where('status', '!=', WorkOrderStatus::CANCELLED)
            ->with(['budget.items', 'confirmedAllocation.lines'])
            ->orderBy('id')
            ->get();

        $data = ['claim' => $this->header($claim)];

        if (in_array(self::BUDGETS, $blocks, true)) {
            $data['budgets'] = $this->budgets($claim);
        }

        if (in_array(self::ALLOCATIONS, $blocks, true)) {
            $data['allocations'] = $this->allocations($claim, $workOrders);
        }

        if (in_array(self::BILLING, $blocks, true)) {
            $data['billing'] = $this->billing($claim, $workOrders);
        }

        $data['alerts'] = $this->alerts($claim, $workOrders, $blocks);

        return $data;
    }

    /**
     * Reporte de siniestros (reports/claims): una fila por siniestro con la fecha del siniestro en el período.
     */
    public function report(CarbonInterface $from, CarbonInterface $to, ?int $insurerId, ?ClaimStatus $status): array
    {
        $rows = Claim::query()
            ->whereDate('loss_date', '>=', $from->toDateString())
            ->whereDate('loss_date', '<=', $to->toDateString())
            ->when($insurerId, fn ($q) => $q->where('insurer_id', $insurerId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('loss_date')
            ->orderBy('id')
            ->get()
            ->map(function (Claim $claim) {
                $summary = $this->summary($claim, [self::BUDGETS, self::ALLOCATIONS, self::BILLING]);
                $billing = collect($summary['billing']['by_recipient'])->keyBy('recipient_type');

                return [
                    'claim_id' => $claim->id,
                    'code' => $claim->code,
                    'claim_number' => $claim->claim_number,
                    'insurer_name' => $summary['claim']['insurer_name'],
                    'status' => $claim->status->label(),
                    'loss_date' => $claim->loss_date->toDateString(),
                    'plate' => $summary['claim']['plate'],
                    'deductible_amount' => $summary['claim']['deductible_amount'],
                    'budgeted_insurer' => $summary['budgets']['totals']['insurer_amount'],
                    'authorized_total' => $summary['budgets']['totals']['authorized_total'],
                    'accepted_owner_amount' => $summary['budgets']['totals']['accepted_owner_amount'],
                    'allocated_insurer' => $summary['allocations']['totals']['insurer_amount'],
                    'allocated_owner' => $summary['allocations']['totals']['owner_amount'],
                    'allocated_workshop' => $summary['allocations']['totals']['workshop_amount'],
                    'invoiced_insurer' => $billing[InvoiceRecipientType::INSURER->value]['net_invoiced'],
                    'collected_insurer' => $billing[InvoiceRecipientType::INSURER->value]['collected'],
                    'balance_insurer' => $billing[InvoiceRecipientType::INSURER->value]['balance'],
                    'invoiced_customer' => $billing[InvoiceRecipientType::CUSTOMER->value]['net_invoiced'],
                    'collected_customer' => $billing[InvoiceRecipientType::CUSTOMER->value]['collected'],
                    'balance_customer' => $billing[InvoiceRecipientType::CUSTOMER->value]['balance'],
                    'alerts_count' => count($summary['alerts']),
                ];
            });

        $amounts = ['budgeted_insurer', 'authorized_total', 'accepted_owner_amount', 'allocated_insurer', 'allocated_owner',
            'allocated_workshop', 'invoiced_insurer', 'collected_insurer', 'balance_insurer', 'invoiced_customer',
            'collected_customer', 'balance_customer'];

        return [
            'period' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'totals' => [
                'claims_count' => $rows->count(),
                ...collect($amounts)->mapWithKeys(fn (string $key) => [$key => $this->sum($rows->pluck($key))])->all(),
            ],
            'claims' => $rows->values(),
        ];
    }

    private function header(Claim $claim): array
    {
        return [
            'id' => $claim->id,
            'code' => $claim->code,
            'claim_number' => $claim->claim_number,
            'policy_number' => $claim->policy_number,
            'status' => ['value' => $claim->status->value, 'label' => $claim->status->label()],
            'claim_type' => ['value' => $claim->claim_type->value, 'label' => $claim->claim_type->label()],
            'loss_date' => $claim->loss_date->toDateString(),
            'insurer_id' => $claim->insurer_id,
            'insurer_name' => $claim->insurer?->client?->display_name,
            'reception_id' => $claim->reception_id,
            'plate' => $claim->reception?->vehicle?->plate,
            'owner_name' => $claim->reception?->client?->display_name,
            'deductible_amount' => $claim->deductible_amount === null ? null : Decimal::of($claim->deductible_amount, 2),
        ];
    }

    /**
     * Presupuestos no cancelados de la recepción. Los totales suman solo los aprobados (los que pasan a OT).
     */
    private function budgets(Claim $claim): array
    {
        $rows = Budget::where('reception_id', $claim->reception_id)
            ->where('status', '!=', BudgetStatus::CANCELLED)
            ->with('items')
            ->orderBy('id')
            ->get()
            ->map(function (Budget $budget) use ($claim) {
                $authorization = $budget->insurerAuthorizations()
                    ->where('claim_id', $claim->id)
                    ->where('status', InsurerAuthorizationStatus::CURRENT)
                    ->first();
                $insurer = $this->sum($budget->items->where('responsibility', BudgetItemResponsibility::INSURER)->pluck('total'));
                $subtotal = Decimal::of($budget->subtotal, 2);
                $acceptance = $this->ownerShare->lastAcceptance($budget);

                return [
                    'budget_id' => $budget->id,
                    'code' => $budget->code,
                    'status' => $budget->status->value,
                    'status_label' => $budget->status->label(),
                    'subtotal' => $subtotal,
                    'insurer_amount' => $insurer,
                    'owner_amount' => bcsub($subtotal, $insurer, 2),
                    'authorization_version' => $authorization?->version,
                    'authorization_result' => $authorization?->result->label(),
                    'authorized_total' => $authorization ? Decimal::of($authorization->authorized_total, 2) : null,
                    'accepted_owner_amount' => $acceptance ? Decimal::of($acceptance->owner_amount, 2) : null,
                ];
            });

        $approved = $rows->where('status', BudgetStatus::APPROVED->value);

        return [
            'totals' => [
                'budgeted_total' => $this->sum($approved->pluck('subtotal')),
                'insurer_amount' => $this->sum($approved->pluck('insurer_amount')),
                'owner_amount' => $this->sum($approved->pluck('owner_amount')),
                'authorized_total' => $this->sum($approved->pluck('authorized_total')),
                'accepted_owner_amount' => $this->sum($approved->pluck('accepted_owner_amount')),
            ],
            'items' => $rows->values(),
        ];
    }

    /**
     * Repartos confirmados del siniestro, por OT.
     */
    private function allocations(Claim $claim, Collection $workOrders): array
    {
        $rows = $workOrders->map(function (WorkOrder $workOrder) use ($claim) {
            $allocation = $this->confirmedAllocation($workOrder, $claim);
            $portion = fn (callable $filter) => $allocation
                ? $this->sum($allocation->lines->filter(fn ($line) => $filter($line->portion_type))->pluck('amount'))
                : '0.00';

            return [
                'work_order_id' => $workOrder->id,
                'work_order_code' => $workOrder->code,
                'work_order_status' => $workOrder->status->label(),
                'requires_allocation' => $workOrder->budget !== null && $this->ownerShare->hasInsurerItems($workOrder->budget),
                'allocation_version' => $allocation?->version,
                'insurer_amount' => $portion(fn ($type) => $type === AllocationPortionType::INSURER_COVERED),
                'owner_amount' => $portion(fn ($type) => $type->isOwner()),
                'workshop_amount' => $portion(fn ($type) => $type === AllocationPortionType::WORKSHOP_ABSORBED),
                'deductible_amount' => $allocation?->deductible_amount === null ? null : Decimal::of($allocation->deductible_amount, 2),
            ];
        });

        $deductible = $this->sum($rows->pluck('deductible_amount'));

        return [
            'totals' => [
                'insurer_amount' => $this->sum($rows->pluck('insurer_amount')),
                'owner_amount' => $this->sum($rows->pluck('owner_amount')),
                'workshop_amount' => $this->sum($rows->pluck('workshop_amount')),
                'deductible_amount' => $deductible,
                'deductible_fiscal_pending' => bccomp($deductible, '0', 2) > 0,
            ],
            'work_orders' => $rows->values(),
        ];
    }

    /**
     * Facturas vigentes de las OT de la recepción, por destinatario: la aseguradora del siniestro o los demás. Los
     * totales suman solo las emitidas; los borradores se listan y se cuentan aparte.
     */
    private function billing(Claim $claim, Collection $workOrders): array
    {
        $insurerClientId = $claim->insurer?->client_id;
        $invoices = Invoice::whereIn('work_order_id', $workOrders->pluck('id'))
            ->whereIn('status', InvoiceStatus::currentStatuses())
            ->with(['payments', 'creditNotes', 'workOrder'])
            ->orderBy('id')
            ->get()
            ->map(function (Invoice $invoice) use ($insurerClientId) {
                $type = $invoice->client_id === $insurerClientId ? InvoiceRecipientType::INSURER : InvoiceRecipientType::CUSTOMER;
                $issued = $invoice->status === InvoiceStatus::ISSUED;

                return [
                    'invoice_id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status->value,
                    'status_label' => $invoice->status->label(),
                    'work_order_code' => $invoice->workOrder?->code,
                    'recipient_type' => $type->value,
                    'recipient_label' => $type->label(),
                    'customer_name' => $invoice->customer_name,
                    'condition' => $invoice->condition->label(),
                    'total' => Decimal::of($invoice->total, 2),
                    'credited_total' => $issued ? $invoice->creditedTotal() : '0.00',
                    'paid_total' => $issued ? $invoice->paidTotal() : '0.00',
                    'balance' => $issued ? $invoice->balance() : '0.00',
                    'payment_status' => $invoice->paymentStatus()?->label(),
                ];
            });

        $byRecipient = collect(InvoiceRecipientType::cases())->map(function (InvoiceRecipientType $type) use ($invoices) {
            $group = $invoices->where('recipient_type', $type->value);
            $issued = $group->where('status', InvoiceStatus::ISSUED->value);
            $invoiced = $this->sum($issued->pluck('total'));
            $credited = $this->sum($issued->pluck('credited_total'));

            return [
                'recipient_type' => $type->value,
                'recipient_label' => $type->label(),
                'invoices_count' => $issued->count(),
                'drafts_count' => $group->where('status', InvoiceStatus::DRAFT->value)->count(),
                'invoiced_total' => $invoiced,
                'credited_total' => $credited,
                'net_invoiced' => bcsub($invoiced, $credited, 2),
                'collected' => $this->sum($issued->pluck('paid_total')),
                'balance' => $this->sum($issued->pluck('balance')),
            ];
        });

        return [
            'totals' => [
                'net_invoiced' => $this->sum($byRecipient->pluck('net_invoiced')),
                'collected' => $this->sum($byRecipient->pluck('collected')),
                'balance' => $this->sum($byRecipient->pluck('balance')),
            ],
            'by_recipient' => $byRecipient->values(),
            'invoices' => $invoices->values(),
        ];
    }

    /**
     * Lo que falta para cerrar el siniestro, según los bloques visibles.
     *
     * @return string[]
     */
    private function alerts(Claim $claim, Collection $workOrders, array $blocks): array
    {
        $alerts = [];

        if ($claim->status === ClaimStatus::OPEN) {
            if ($claim->deductible_amount === null) {
                $alerts[] = 'El deducible del siniestro es desconocido: el reparto no se confirma y la recepción no se entrega hasta informarlo.';
            } elseif (bccomp((string) $claim->deductible_amount, '0', 2) > 0) {
                if (!$this->allocationBilling->netMode($claim)) {
                    $alerts[] = 'El deducible del siniestro es mayor a 0 y no tiene la modalidad neta: no se factura ni se entrega por el flujo habitual (decisión A).';
                } elseif ($claim->deductible_includes_tax === null) {
                    $alerts[] = 'Indique si el deducible del siniestro incluye el IVA: sin eso no se reparte ni se factura.';
                }
            }
        }

        foreach ($workOrders as $workOrder) {
            if ($workOrder->status !== WorkOrderStatus::COMPLETED || !$workOrder->budget || !$this->ownerShare->hasInsurerItems($workOrder->budget)) {
                continue;
            }

            $allocation = $this->confirmedAllocation($workOrder, $claim);

            if (!$allocation) {
                if (in_array(self::ALLOCATIONS, $blocks, true)) {
                    $alerts[] = "La OT {$workOrder->code} no tiene un reparto confirmado.";
                }
            } elseif (in_array(self::BILLING, $blocks, true) && !$this->allocationBilling->isFullyInvoiced($allocation)) {
                $alerts[] = "Al reparto de la OT {$workOrder->code} le faltan porciones por facturar o emitir.";
            }
        }

        return $alerts;
    }

    private function confirmedAllocation(WorkOrder $workOrder, Claim $claim): ?WorkOrderAllocation
    {
        $allocation = $workOrder->confirmedAllocation;

        return $allocation && $allocation->claim_id === $claim->id ? $allocation : null;
    }

    private function sum(Collection $values): string
    {
        return $values->reduce(fn (string $sum, $value) => bcadd($sum, (string) ($value ?? '0'), 2), '0.00');
    }
}

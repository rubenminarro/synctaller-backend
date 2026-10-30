<?php

namespace App\Services;

use App\Enums\BudgetStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InvoiceCondition;
use App\Enums\InvoiceRecipientType;
use App\Enums\InvoiceStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\ReceptionStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Budget;
use App\Models\Client;
use App\Models\InventoryMovement;
use App\Models\Insurer;
use App\Models\Invoice;
use App\Models\InvoiceCreditNote;
use App\Models\Mechanic;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\Reception;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderClosing;
use App\Models\WorkOrderCommission;
use App\Models\WorkOrderItemMechanic;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Reportes y dashboard (FASE 20). Solo lectura. Ventas por fecha de emisión
 * de la factura; rentabilidad y productividad por fecha de cierre de la OT.
 * Importes de la factura con IVA; los del cierre, sin IVA.
 */
class ReportService
{
    private const OPEN_WORK_ORDER_STATUSES = [
        WorkOrderStatus::PENDING,
        WorkOrderStatus::IN_PROGRESS,
        WorkOrderStatus::PAUSED,
    ];

    public function __construct(
        protected PaymentService $paymentService,
        protected MaintenanceService $maintenanceService
    ) {
    }

    /**
     * @param string[] $allowed bloques que el usuario puede ver
     */
    public function dashboard(array $allowed): array
    {
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();

        $blocks = [
            'workshop' => [
                'vehicles_in_shop' => Reception::whereNotIn('status', [ReceptionStatus::DELIVERED, ReceptionStatus::CANCELLED])->count(),
                'open_work_orders' => collect(self::OPEN_WORK_ORDER_STATUSES)->mapWithKeys(
                    fn (WorkOrderStatus $s) => [$s->value => WorkOrder::where('status', $s)->count()]
                ),
                'completed_this_month' => WorkOrder::where('status', WorkOrderStatus::COMPLETED)
                    ->whereBetween('completed_at', [$from, $to])->count(),
            ],
        ];

        if (in_array('sales', $allowed, true)) {
            $sales = $this->sales($from, $to, 'month');

            $blocks['sales'] = [
                'invoiced_this_month' => $sales['totals']['total'],
                'collected_this_month' => $sales['collected']['total'],
                'receivables' => $this->receivablesTotal(),
            ];
        }

        if (in_array('budgets', $allowed, true)) {
            $budgets = $this->budgets($from, $to);

            $blocks['budgets'] = [
                'awaiting_response' => Budget::where('status', BudgetStatus::SENT)->count(),
                'awaiting_response_total' => $this->sum(Budget::where('status', BudgetStatus::SENT)->pluck('total')),
                'approval_rate_this_month' => $budgets['approval_rate'],
            ];
        }

        if (in_array('profitability', $allowed, true)) {
            $profit = $this->profitability($from, $to);

            $blocks['profitability'] = [
                'gross_margin_this_month' => $profit['totals']['gross_margin'],
                'margin_percentage_this_month' => $profit['totals']['margin_percentage'],
            ];
        }

        if (in_array('productivity', $allowed, true)) {
            $blocks['productivity'] = [
                'jobs_completed_this_month' => WorkOrderCommission::whereBetween('earned_at', [$from, $to])
                    ->distinct('work_order_item_id')->count('work_order_item_id'),
            ];
        }

        if (in_array('inventory', $allowed, true)) {
            $blocks['inventory'] = [
                'stock_value' => $this->stockValue(),
                'below_minimum' => $this->belowMinimum()->count(),
            ];
        }

        if (in_array('maintenance', $allowed, true)) {
            $alerts = $this->maintenanceService->alerts(null);

            $blocks['maintenance'] = [
                'overdue' => $alerts->where('status.value', MaintenanceStatus::OVERDUE->value)->count(),
                'due_soon' => $alerts->where('status.value', MaintenanceStatus::DUE_SOON->value)->count(),
            ];
        }

        return $blocks;
    }

    /**
     * Facturas emitidas (por fecha de emisión), cobros por medio y anuladas.
     */
    public function sales(CarbonInterface $from, CarbonInterface $to, string $groupBy): array
    {
        $invoices = Invoice::query()
            ->where('status', InvoiceStatus::ISSUED)
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->get();

        $cancelled = Invoice::query()
            ->where('status', InvoiceStatus::CANCELLED)
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->get();

        $creditNotes = InvoiceCreditNote::registered()
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->get();

        $format = $groupBy === 'day' ? 'Y-m-d' : 'Y-m';
        $total = $this->sum($invoices->pluck('total'));
        $collected = $this->paymentService->summary($from, $to);

        return [
            'period' => $this->period($from, $to),
            'totals' => [
                'invoices_count' => $invoices->count(),
                'total' => $total,
                'exempt' => $this->sum($invoices->pluck('total_exempt')),
                'taxed_5' => $this->sum($invoices->pluck('total_5')),
                'taxed_10' => $this->sum($invoices->pluck('total_10')),
                'iva_5' => $this->sum($invoices->pluck('iva_5')),
                'iva_10' => $this->sum($invoices->pluck('iva_10')),
                'discount' => $this->sum($invoices->pluck('discount_total')),
                'average_ticket' => $invoices->isEmpty() ? '0.00' : Decimal::round(bcdiv($total, (string) $invoices->count(), 8), 2),
            ],
            'by_period' => $invoices
                ->groupBy(fn (Invoice $i) => $i->issue_date->format($format))
                ->sortKeys()
                ->map(fn (Collection $group, string $key) => [
                    'period' => $key,
                    'invoices_count' => $group->count(),
                    'total' => $this->sum($group->pluck('total')),
                ])
                ->values(),
            'by_condition' => $invoices
                ->groupBy(fn (Invoice $i) => $i->condition->value)
                ->map(fn (Collection $group, string $condition) => [
                    'condition' => $condition,
                    'invoices_count' => $group->count(),
                    'total' => $this->sum($group->pluck('total')),
                ])
                ->values(),
            'collected' => [
                'total' => $collected['total'],
                'payments_count' => $collected['payments_count'],
                'by_method' => $collected['by_method'],
            ],
            'cancelled' => [
                'invoices_count' => $cancelled->count(),
                'total' => $this->sum($cancelled->pluck('total')),
            ],
            // Notas de crédito registradas en el período, por su fecha (P10). `total` sigue siendo lo emitido.
            'credit_notes' => [
                'credit_notes_count' => $creditNotes->count(),
                'total' => $this->sum($creditNotes->pluck('total')),
                'iva_5' => $this->sum($creditNotes->pluck('iva_5')),
                'iva_10' => $this->sum($creditNotes->pluck('iva_10')),
            ],
            'net_total' => bcsub($total, $this->sum($creditNotes->pluck('total')), 2),
        ];
    }

    /**
     * Presupuestos creados en el período según su estado actual.
     * Tasa de aprobación = aprobados / (aprobados + rechazados).
     */
    public function budgets(CarbonInterface $from, CarbonInterface $to): array
    {
        $budgets = Budget::query()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        $byStatus = collect(BudgetStatus::cases())->map(function (BudgetStatus $status) use ($budgets) {
            $group = $budgets->where('status', $status);

            return [
                'status' => $status->value,
                'budgets_count' => $group->count(),
                'total' => $this->sum($group->pluck('total')),
            ];
        })->values();

        $approved = $budgets->where('status', BudgetStatus::APPROVED)->count();
        $rejected = $budgets->where('status', BudgetStatus::REJECTED)->count();

        return [
            'period' => $this->period($from, $to),
            'budgets_count' => $budgets->count(),
            'total' => $this->sum($budgets->pluck('total')),
            'by_status' => $byStatus,
            'approval_rate' => ($approved + $rejected) > 0
                ? Decimal::round(bcdiv(bcmul((string) $approved, '100', 8), (string) ($approved + $rejected), 8), 2)
                : null,
        ];
    }

    /**
     * Cierres económicos del período (sin IVA).
     */
    public function profitability(CarbonInterface $from, CarbonInterface $to): array
    {
        $closings = WorkOrderClosing::query()
            ->whereBetween('closed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with('workOrder')
            ->get();

        $sale = $this->sum($closings->pluck('sale_total'));
        $margin = $this->sum($closings->pluck('gross_margin'));

        $ranked = $closings
            ->filter(fn ($c) => $c->margin_percentage !== null)
            ->sortBy(fn ($c) => (float) $c->margin_percentage)
            ->map(fn ($c) => [
                'work_order_id' => $c->work_order_id,
                'work_order_code' => $c->workOrder?->code,
                'sale_total' => $c->sale_total,
                'gross_margin' => $c->gross_margin,
                'margin_percentage' => $c->margin_percentage,
                'is_cost_complete' => $c->is_cost_complete,
            ])
            ->values();

        return [
            'period' => $this->period($from, $to),
            'totals' => [
                'work_orders_count' => $closings->count(),
                'sale_labor' => $this->sum($closings->pluck('sale_labor')),
                'sale_parts' => $this->sum($closings->pluck('sale_parts')),
                'sale_total' => $sale,
                'cost_parts' => $this->sum($closings->pluck('cost_parts')),
                'cost_labor' => $this->sum($closings->pluck('cost_labor')),
                'commission_total' => $this->sum($closings->pluck('commission_total')),
                'cost_total' => $this->sum($closings->pluck('cost_total')),
                'gross_margin' => $margin,
                'margin_percentage' => bccomp($sale, '0', 2) > 0
                    ? Decimal::round(bcdiv(bcmul($margin, '100', 8), $sale, 8), 2)
                    : null,
                'incomplete_cost_count' => $closings->where('is_cost_complete', false)->count(),
            ],
            'lowest_margin' => $ranked->take(5)->values(),
            'highest_margin' => $ranked->reverse()->take(5)->values(),
        ];
    }

    /**
     * Por mecánico: OT como responsable, trabajos, venta de mano de obra
     * atribuida (total × participación), comisiones y horas.
     */
    public function productivity(CarbonInterface $from, CarbonInterface $to): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        $commissions = WorkOrderCommission::whereBetween('earned_at', [$start, $end])->get()->groupBy('mechanic_id');

        $hours = WorkOrderItemMechanic::query()
            ->whereHas('workOrderItem.workOrder', fn ($q) => $q->where('status', WorkOrderStatus::COMPLETED)->whereBetween('completed_at', [$start, $end]))
            ->get()
            ->groupBy('mechanic_id');

        $responsible = WorkOrder::where('status', WorkOrderStatus::COMPLETED)
            ->whereBetween('completed_at', [$start, $end])
            ->selectRaw('mechanic_id, COUNT(*) as total')
            ->groupBy('mechanic_id')
            ->pluck('total', 'mechanic_id');

        $mechanicIds = $commissions->keys()->merge($hours->keys())->merge($responsible->keys())->unique();

        $rows = Mechanic::withTrashed()->with('user')->whereIn('id', $mechanicIds)->get()->map(function (Mechanic $mechanic) use ($commissions, $hours, $responsible) {
            $lines = $commissions->get($mechanic->id, collect());
            $assignments = $hours->get($mechanic->id, collect());

            return [
                'mechanic_id' => $mechanic->id,
                'employee_code' => $mechanic->employee_code,
                'full_name' => $mechanic->fullName(),
                'work_orders_as_responsible' => (int) ($responsible[$mechanic->id] ?? 0),
                'jobs_completed' => $lines->pluck('work_order_item_id')->unique()->count(),
                'labor_sale_attributed' => $lines->reduce(
                    fn (string $sum, $line) => bcadd($sum, Decimal::round(bcdiv(bcmul($line->base_amount, $line->share_percentage, 8), '100', 8), 2), 2),
                    '0.00'
                ),
                'commission_total' => $this->sum($lines->pluck('amount')),
                'hours_logged' => $this->sum($assignments->pluck('hours')->filter(fn ($h) => $h !== null)),
                'labor_cost' => $assignments->reduce(fn (string $sum, $a) => bcadd($sum, $a->laborCost() ?? '0', 2), '0.00'),
            ];
        })->sortByDesc(fn ($row) => (float) $row['labor_sale_attributed'])->values();

        return [
            'period' => $this->period($from, $to),
            'mechanics' => $rows,
        ];
    }

    /**
     * Valor del stock y bajo mínimo (a hoy); consumo y movimientos del período.
     */
    public function inventory(CarbonInterface $from, CarbonInterface $to): array
    {
        $movements = InventoryMovement::query()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with('part')
            ->get();

        $consumption = $movements->filter(fn ($m) => in_array(
            $m->type,
            [InventoryMovementType::WORK_ORDER_OUT, InventoryMovementType::WORK_ORDER_RETURN],
            true
        ));

        return [
            'period' => $this->period($from, $to),
            'stock_value' => $this->stockValue(),
            'below_minimum' => $this->belowMinimum()->values(),
            'top_consumed' => $consumption
                ->groupBy('part_id')
                ->map(function (Collection $group) {
                    // Las salidas tienen cantidad negativa y las devoluciones positiva.
                    $net = bcmul($this->sum($group->pluck('quantity'), 3), '-1', 3);

                    return [
                        'part_id' => $group->first()->part_id,
                        'part_code' => $group->first()->part?->code,
                        'name' => $group->first()->part?->name,
                        'quantity' => $net,
                        'cost' => bcmul($this->sum($group->pluck('total_cost')), '-1', 2),
                    ];
                })
                ->filter(fn ($row) => bccomp($row['quantity'], '0', 3) > 0)
                ->sortByDesc(fn ($row) => (float) $row['quantity'])
                ->take(10)
                ->values(),
            'movements_by_type' => $movements
                ->groupBy(fn ($m) => $m->type->value)
                ->map(fn (Collection $group, string $type) => [
                    'type' => $type,
                    'movements_count' => $group->count(),
                    'quantity' => $this->sum($group->pluck('quantity'), 3),
                    'total_cost' => $this->sum($group->pluck('total_cost')),
                ])
                ->values(),
        ];
    }

    /**
     * Clientes que más facturan y nuevos (período); saldos por cobrar (a hoy);
     * vehículos con más visitas (período).
     */
    public function clients(CarbonInterface $from, CarbonInterface $to): array
    {
        $invoices = Invoice::query()
            ->where('status', InvoiceStatus::ISSUED)
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->with('client')
            ->get();

        $insurerClients = $this->insurerClientIds();

        $receivables = Invoice::where('status', InvoiceStatus::ISSUED)->with(['client', 'payments', 'creditNotes'])->get()
            ->map(fn (Invoice $i) => ['invoice' => $i, 'balance' => $i->balance()])
            ->filter(fn ($row) => bccomp($row['balance'], '0', 2) > 0)
            ->groupBy(fn ($row) => $row['invoice']->client_id)
            ->map(fn (Collection $rows) => [
                'client_id' => $rows->first()['invoice']->client_id,
                'full_name' => $this->clientName($rows->first()['invoice']->client),
                'phone' => $rows->first()['invoice']->client?->phone,
                ...$this->recipient($this->recipientType($rows->first()['invoice']->client_id, $insurerClients)),
                'invoices_count' => $rows->count(),
                'balance' => $this->sum($rows->pluck('balance')),
                'oldest_due_date' => $rows->map(fn ($row) => $row['invoice']->due_date ?? $row['invoice']->issue_date)->min()?->toDateString(),
            ])
            ->sortByDesc(fn ($row) => (float) $row['balance'])
            ->values();

        $visits = Reception::query()
            ->whereDate('reception_date', '>=', $from->toDateString())
            ->whereDate('reception_date', '<=', $to->toDateString())
            ->selectRaw('vehicle_id, COUNT(*) as visits')
            ->groupBy('vehicle_id')
            ->orderByDesc('visits')
            ->limit(10)
            ->get();

        $vehicles = Vehicle::withTrashed()->whereIn('id', $visits->pluck('vehicle_id'))->get()->keyBy('id');

        return [
            'period' => $this->period($from, $to),
            'new_clients' => Client::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count(),
            'top_clients' => $invoices
                ->groupBy('client_id')
                ->map(fn (Collection $group) => [
                    'client_id' => $group->first()->client_id,
                    'full_name' => $this->clientName($group->first()->client),
                    'invoices_count' => $group->count(),
                    'total' => $this->sum($group->pluck('total')),
                ])
                ->sortByDesc(fn ($row) => (float) $row['total'])
                ->take(10)
                ->values(),
            'receivables' => [
                'total' => $this->sum($receivables->pluck('balance')),
                'clients' => $receivables,
            ],
            'top_vehicles' => $visits->map(fn ($row) => [
                'vehicle_id' => $row->vehicle_id,
                'plate' => $vehicles->get($row->vehicle_id)?->plate,
                'visits' => (int) $row->visits,
            ])->values(),
        ];
    }

    /**
     * Cuentas por cobrar a hoy (M11), por tipo de destinatario: facturas emitidas con saldo (descontados pagos y notas
     * de crédito), con IVA. Vence en due_date (crédito) o en la fecha de emisión (contado), como las cuentas por pagar;
     * vencida si es anterior a hoy. Con client_id, además el detalle de sus facturas.
     */
    public function receivables(?InvoiceRecipientType $recipientType = null, ?int $clientId = null): array
    {
        $today = now()->startOfDay();
        $insurerClients = $this->insurerClientIds();

        $rows = Invoice::where('status', InvoiceStatus::ISSUED)
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->with(['client', 'payments', 'creditNotes', 'workOrder', 'allocation.claim'])
            ->orderBy('id')
            ->get()
            ->map(function (Invoice $invoice) use ($today, $insurerClients) {
                $due = $invoice->condition === InvoiceCondition::CREDIT && $invoice->due_date ? $invoice->due_date : $invoice->issue_date;

                return [
                    'invoice' => $invoice,
                    'balance' => $invoice->balance(),
                    'type' => $this->recipientType($invoice->client_id, $insurerClients),
                    'due_date' => $due,
                    'days_overdue' => $due->lt($today) ? (int) $due->diffInDays($today) : 0,
                ];
            })
            ->filter(fn (array $row) => bccomp($row['balance'], '0', 2) > 0)
            ->filter(fn (array $row) => !$recipientType || $row['type'] === $recipientType)
            ->values();

        $types = $recipientType ? [$recipientType] : InvoiceRecipientType::cases();

        $report = [
            'as_of' => $today->toDateString(),
            'totals' => $this->receivableTotals($rows),
            'by_recipient' => collect($types)->map(fn (InvoiceRecipientType $type) => [
                ...$this->recipient($type),
                ...$this->receivableTotals($rows->filter(fn (array $row) => $row['type'] === $type)),
            ])->values(),
            'clients' => $rows
                ->groupBy(fn (array $row) => $row['invoice']->client_id)
                ->map(fn (Collection $group) => [
                    'client_id' => $group->first()['invoice']->client_id,
                    'full_name' => $this->clientName($group->first()['invoice']->client),
                    'document_number' => $group->first()['invoice']->client?->document_number,
                    ...$this->recipient($group->first()['type']),
                    'invoices_count' => $group->count(),
                    'balance' => $this->sum($group->pluck('balance')),
                    'overdue' => $this->sum($group->where('days_overdue', '>', 0)->pluck('balance')),
                    'oldest_due_date' => $group->pluck('due_date')->min()->toDateString(),
                    'max_days_overdue' => $group->max('days_overdue'),
                ])
                ->sortByDesc(fn (array $row) => (float) $row['balance'])
                ->values(),
        ];

        if ($clientId) {
            $report['invoices'] = $rows->map(fn (array $row) => [
                'invoice_id' => $row['invoice']->id,
                'number' => $row['invoice']->number,
                'issue_date' => $row['invoice']->issue_date->toDateString(),
                'condition' => $row['invoice']->condition->label(),
                'due_date' => $row['due_date']->toDateString(),
                'days_overdue' => $row['days_overdue'],
                'work_order_code' => $row['invoice']->workOrder?->code,
                'claim_code' => $row['invoice']->allocation?->claim?->code,
                'total' => Decimal::of($row['invoice']->total, 2),
                'paid_total' => $row['invoice']->paidTotal(),
                'credited_total' => $row['invoice']->creditedTotal(),
                'balance' => $row['balance'],
            ])->values();
        }

        return $report;
    }

    /**
     * Saldo, vencido y antigüedad (días de atraso) de un grupo de facturas por cobrar.
     */
    private function receivableTotals(Collection $rows): array
    {
        $bucket = fn (int $from, ?int $to) => $this->sum($rows
            ->filter(fn (array $row) => $row['days_overdue'] >= $from && ($to === null || $row['days_overdue'] <= $to))
            ->pluck('balance'));

        return [
            'invoices_count' => $rows->count(),
            'balance' => $this->sum($rows->pluck('balance')),
            'overdue' => $bucket(1, null),
            'not_overdue' => $bucket(0, 0),
            'days_1_30' => $bucket(1, 30),
            'days_31_60' => $bucket(31, 60),
            'days_61_90' => $bucket(61, 90),
            'days_over_90' => $bucket(91, null),
        ];
    }

    /**
     * Clientes de aseguradoras, también de las dadas de baja: sus facturas siguen siendo de una aseguradora.
     *
     * @return Collection<int, int>
     */
    private function insurerClientIds(): Collection
    {
        return Insurer::withTrashed()->pluck('client_id');
    }

    private function recipientType(?int $clientId, Collection $insurerClients): InvoiceRecipientType
    {
        return $insurerClients->contains($clientId) ? InvoiceRecipientType::INSURER : InvoiceRecipientType::CUSTOMER;
    }

    private function recipient(InvoiceRecipientType $type): array
    {
        return ['recipient_type' => $type->value, 'recipient_label' => $type->label()];
    }

    private function receivablesTotal(): string
    {
        return Invoice::where('status', InvoiceStatus::ISSUED)->with(['payments', 'creditNotes'])->get()
            ->reduce(fn (string $sum, Invoice $i) => bcadd($sum, $i->balance(), 2), '0.00');
    }

    private function stockValue(): string
    {
        return PartStock::all()->reduce(fn (string $sum, PartStock $s) => bcadd($sum, $s->stockValue(), 2), '0.00');
    }

    /**
     * Repuestos con mínimo definido y disponible por debajo (sin stock = 0).
     */
    private function belowMinimum(): Collection
    {
        return Part::query()
            ->where('minimum_stock', '>', 0)
            ->with('stock')
            ->get()
            ->filter(fn (Part $part) => $part->stock
                ? $part->stock->isBelowMinimum($part->minimum_stock)
                : true)
            ->map(fn (Part $part) => [
                'part_id' => $part->id,
                'part_code' => $part->code,
                'name' => $part->name,
                'minimum_stock' => $part->minimum_stock,
                'quantity_on_hand' => $part->stock?->quantity_on_hand ?? '0.000',
                'quantity_available' => $part->stock?->availableQuantity() ?? '0.000',
            ]);
    }

    private function sum(Collection $values, int $scale = 2): string
    {
        return $values->reduce(
            fn (string $sum, $value) => bcadd($sum, (string) ($value ?? '0'), $scale),
            bcadd('0', '0', $scale)
        );
    }

    private function period(CarbonInterface $from, CarbonInterface $to): array
    {
        return ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()];
    }

    private function clientName(?Client $client): ?string
    {
        return $client ? $client->display_name : null;
    }
}

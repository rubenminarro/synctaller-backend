<?php

namespace App\Services;

use App\Enums\AllocationPortionType;
use App\Enums\AllocationStatus;
use App\Enums\InvoiceCondition;
use App\Enums\InvoiceStatus;
use App\Enums\TaxRate;
use App\Enums\WorkOrderStatus;
use App\Exceptions\AllocationException;
use App\Exceptions\InvoiceException;
use App\Models\Claim;
use App\Models\Client;
use App\Models\Insurer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceStamp;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAllocation;
use App\Models\WorkOrderAllocationLine;
use App\Support\Decimal;
use App\Support\Format;
use Illuminate\Support\Facades\DB;

/**
 * Facturación de OT completadas (FASE 15). Las líneas salen de lo realmente
 * usado (lo mismo que el cierre congelado); los precios del sistema son sin
 * IVA y la factura los expresa con IVA incluido, en guaraníes.
 */
class InvoiceService
{
    public function __construct(
        protected WorkOrderSaleBreakdown $saleBreakdown,
        protected OwnerShareService $ownerShare,
        protected AllocationBillingService $allocationBilling
    ) {
    }

    /**
     * Factura de un responsable desde el reparto confirmado (M9): una línea por porción suya con algo pendiente (porción
     * menos lo facturado neto). Si se factura el concepto entero va con su cantidad y precio; si es una parte, con
     * cantidad 1 y la cantidad real en la descripción (Ejemplo 5: la base queda exacta). La de la aseguradora sale a
     * crédito con su plazo de pago; la del dueño, al contado. Una vigente por cliente y versión del reparto. Orden de
     * bloqueo: siniestro → OT → reparto → factura.
     */
    public function generateForAllocation(WorkOrderAllocation $allocation, int $clientId, User $user): Invoice
    {
        return DB::transaction(function () use ($allocation, $clientId, $user) {
            $claim = Claim::whereKey($allocation->claim_id)->lockForUpdate()->firstOrFail();
            $workOrder = WorkOrder::whereKey($allocation->work_order_id)->lockForUpdate()->firstOrFail();
            $allocation = WorkOrderAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();

            if ($allocation->status !== AllocationStatus::CONFIRMED) {
                throw new AllocationException('Solo se factura desde el reparto confirmado.', 422, 'ALLOCATION_NOT_CONFIRMED');
            }

            $this->allocationBilling->ensureDeductibleAllowsInvoicing($claim);
            $this->allocationBilling->ensureDeductibleAllocated($allocation);

            $portions = $allocation->lines()->where('client_id', $clientId)->get();

            if ($portions->isEmpty()) {
                throw new AllocationException('El cliente no tiene porciones en este reparto.', 422, 'ALLOCATION_CLIENT_NOT_RESPONSIBLE');
            }

            $exists = Invoice::where('allocation_id', $allocation->id)->where('client_id', $clientId)
                ->whereIn('status', InvoiceStatus::currentStatuses())->exists();

            if ($exists) {
                throw new InvoiceException('El cliente ya tiene una factura vigente de este reparto.', 409, 'WORK_ORDER_ALREADY_INVOICED');
            }

            $billed = $this->allocationBilling->billedByLine($allocation);
            $concepts = $this->saleBreakdown->concepts($workOrder)->keyBy(fn (array $c) => $c['source_type'] . ':' . $c['source_id']);
            $lines = $portions
                ->map(fn ($portion) => ['portion' => $portion, 'pending' => bcsub(Decimal::of($portion->amount, 2), $billed[$portion->id] ?? '0.00', 2)])
                ->filter(fn (array $row) => bccomp($row['pending'], '0', 2) > 0)
                ->map(fn (array $row) => $this->lineFromPortion($row['portion'], $row['pending'], $concepts[$row['portion']->source_type . ':' . $row['portion']->source_id] ?? null, $claim));

            if ($lines->isEmpty()) {
                throw new AllocationException('El cliente no tiene nada pendiente de facturar en este reparto.', 422, 'ALLOCATION_NOTHING_TO_INVOICE');
            }

            $client = Client::withTrashed()->findOrFail($clientId);
            $insurer = Insurer::where('client_id', $clientId)->first();

            $invoice = Invoice::create([
                'work_order_id' => $workOrder->id,
                'allocation_id' => $allocation->id,
                'client_id' => $client->id,
                'status' => InvoiceStatus::DRAFT,
                'customer_name' => $client->display_name,
                'customer_tax_id' => $client->document_number,
                'customer_address' => $client->address,
                'condition' => $insurer ? InvoiceCondition::CREDIT : InvoiceCondition::CASH,
                'due_date' => $insurer ? now()->addDays($insurer->payment_term_days ?? 30)->toDateString() : null,
                'created_by' => $user->id,
            ]);

            $defaultRate = TaxRate::from((int) config('invoice.default_tax_rate', 10));

            foreach ($lines as $line) {
                $item = new InvoiceItem([...$line, 'tax_rate' => $defaultRate, 'discount' => '0']);
                $item->recalculate();
                $invoice->items()->save($item);
            }

            $this->recalculateTotals($invoice);

            return $this->load($invoice);
        });
    }

    public function generate(WorkOrder $workOrder, User $user): Invoice
    {
        return DB::transaction(function () use ($workOrder, $user) {

            $workOrder = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($workOrder->status !== WorkOrderStatus::COMPLETED || !$workOrder->closing) {
                throw new InvoiceException(
                    'Solo puede facturarse una orden de trabajo completada.',
                    422,
                    'WORK_ORDER_NOT_INVOICEABLE'
                );
            }

            // Siniestro (M8, R10): una OT con reparto nunca se factura entera al dueño; se factura por responsable (M9).
            if ($this->ownerShare->requiresAllocation($workOrder)) {
                throw new InvoiceException(
                    'La orden de trabajo es de un siniestro con líneas a cargo de la aseguradora: se factura por responsable desde su reparto.',
                    422,
                    'WORK_ORDER_REQUIRES_ALLOCATION_INVOICING'
                );
            }

            if ($workOrder->invoices()->current()->exists()) {
                throw new InvoiceException(
                    'La orden de trabajo ya tiene una factura vigente.',
                    409,
                    'WORK_ORDER_ALREADY_INVOICED'
                );
            }

            $workOrder->load(['items.budgetItem', 'parts.part', 'reception.client']);

            $lines = $this->linesFromWorkOrder($workOrder);

            $baseTotal = array_reduce($lines, fn (string $sum, array $line) => bcadd($sum, $line['base_total'], 2), '0.00');

            // Control: la factura debe coincidir con la venta del cierre congelado.
            if (bccomp($baseTotal, $workOrder->closing->sale_total, 2) !== 0) {
                throw new InvoiceException(
                    "La factura ({$baseTotal}) no coincide con la venta del cierre ({$workOrder->closing->sale_total}).",
                    409,
                    'INVOICE_TOTAL_MISMATCH'
                );
            }

            $client = $workOrder->reception->client;

            $invoice = Invoice::create([
                'work_order_id' => $workOrder->id,
                'client_id' => $client->id,
                'status' => InvoiceStatus::DRAFT,
                'customer_name' => $client->display_name,
                'customer_tax_id' => $client->document_number,
                'condition' => InvoiceCondition::CASH,
                'created_by' => $user->id,
            ]);

            $defaultRate = TaxRate::from((int) config('invoice.default_tax_rate', 10));

            foreach ($lines as $line) {
                $item = new InvoiceItem([...$line, 'tax_rate' => $defaultRate, 'discount' => '0']);
                $item->recalculate();
                $invoice->items()->save($item);
            }

            $this->recalculateTotals($invoice);

            return $this->load($invoice);
        });
    }

    public function update(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {

            $invoice = $this->lockDraft($invoice);

            $invoice->fill($data);

            if ($invoice->condition === InvoiceCondition::CASH) {
                $invoice->due_date = null;
            }

            $invoice->save();

            return $this->load($invoice);
        });
    }

    public function updateItem(Invoice $invoice, InvoiceItem $item, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $item, $data) {

            $invoice = $this->lockDraft($invoice);
            $item->refresh();

            $item->fill(array_intersect_key($data, array_flip(['description', 'tax_rate', 'discount'])));
            $item->recalculate();

            if (bccomp($item->total, '0', 2) < 0) {
                throw new InvoiceException(
                    "El descuento no puede superar el importe de la línea ({$item->grossAmount()}).",
                    422,
                    'INVOICE_DISCOUNT_EXCEEDS_AMOUNT'
                );
            }

            $item->save();

            $this->recalculateTotals($invoice);

            return $this->load($invoice);
        });
    }

    public function issue(Invoice $invoice, ?int $stampId, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $stampId, $user) {

            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== InvoiceStatus::DRAFT) {
                throw new InvoiceException(
                    "La factura {$invoice->status->label()} no puede emitirse.",
                    422,
                    'INVOICE_NOT_ISSUABLE'
                );
            }

            // Factura de un reparto (M9): la versión sigue confirmada, el deducible lo permite (A) y R8.
            if ($invoice->allocation_id !== null) {
                $this->allocationBilling->ensureIssuable($invoice);
            }

            if (blank($invoice->customer_tax_id)) {
                throw new InvoiceException(
                    'Debe informar el RUC o CI del cliente para emitir la factura.',
                    422,
                    'INVOICE_CUSTOMER_TAX_ID_REQUIRED'
                );
            }

            $today = now()->startOfDay();

            if (
                $invoice->condition === InvoiceCondition::CREDIT &&
                ($invoice->due_date === null || $invoice->due_date->lt($today))
            ) {
                throw new InvoiceException(
                    'Una factura a crédito requiere una fecha de vencimiento igual o posterior a hoy.',
                    422,
                    'INVOICE_DUE_DATE_REQUIRED'
                );
            }

            $stamp = $this->lockStamp($stampId, $today);

            if ($stamp->next_number > InvoiceStamp::MAX_NUMBER) {
                throw new InvoiceException(
                    "El timbrado {$stamp->stamp_number} agotó su numeración.",
                    422,
                    'INVOICE_STAMP_EXHAUSTED'
                );
            }

            $number = $stamp->formatNumber($stamp->next_number);

            $stamp->update(['next_number' => $stamp->next_number + 1]);

            $invoice->update([
                'status' => InvoiceStatus::ISSUED,
                'invoice_stamp_id' => $stamp->id,
                'stamp_number' => $stamp->stamp_number,
                'stamp_valid_until' => $stamp->valid_until,
                'number' => $number,
                'issue_date' => $today,
                'issued_at' => now(),
                'issued_by' => $user->id,
            ]);

            return $this->load($invoice);
        });
    }

    public function cancel(Invoice $invoice, string $reason, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $user) {

            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== InvoiceStatus::ISSUED) {
                throw new InvoiceException(
                    'Solo puede anularse una factura emitida.',
                    422,
                    'INVOICE_NOT_CANCELLABLE'
                );
            }

            if ($invoice->payments()->registered()->exists()) {
                throw new InvoiceException(
                    'La factura tiene pagos registrados: anúlelos antes de anular la factura.',
                    422,
                    'INVOICE_HAS_PAYMENTS'
                );
            }

            // Una factura acreditada se corrige con sus notas de crédito (P10): no se anula encima.
            if ($invoice->creditNotes()->registered()->exists()) {
                throw new InvoiceException(
                    'La factura tiene notas de crédito registradas: anúlelas antes de anular la factura.',
                    422,
                    'INVOICE_HAS_CREDIT_NOTES'
                );
            }

            $invoice->update([
                'status' => InvoiceStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ]);

            return $this->load($invoice);
        });
    }

    public function delete(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $this->lockDraft($invoice)->delete();
        });
    }

    /**
     * Mano de obra y servicios no cancelados, repuestos fuera de catálogo no
     * cancelados (lo presupuestado) y repuestos de catálogo usados (cantidad neta).
     */
    /**
     * Línea de factura de una porción del reparto: el concepto entero con su cantidad y precio, o una parte con
     * cantidad 1 (la base es el importe exacto y la cantidad real va en la descripción).
     */
    private function lineFromPortion(WorkOrderAllocationLine $portion, string $amount, ?array $concept, Claim $claim): array
    {
        // El deducible (modalidad neta, M10) va con su concepto propio, cantidad 1, en la factura del dueño.
        if ($portion->portion_type === AllocationPortionType::OWNER_DEDUCTIBLE) {
            return [
                'source_type' => $portion->source_type,
                'source_id' => $portion->source_id,
                'allocation_line_id' => $portion->id,
                'description' => 'Deducible de siniestro N.º ' . ($claim->claim_number ?? $claim->code),
                'quantity' => '1',
                'base_unit_price' => $amount,
                'base_total' => $amount,
            ];
        }

        $whole = $concept && bccomp($amount, Decimal::of($concept['base_total'], 2), 2) === 0;

        return [
            'source_type' => $portion->source_type,
            'source_id' => $portion->source_id,
            'allocation_line_id' => $portion->id,
            'description' => $whole
                ? $portion->description
                : mb_substr($portion->description . ' (parte; cantidad real ' . Format::quantity($concept['quantity'] ?? '1') . ')', 0, 255),
            'quantity' => $whole ? $concept['quantity'] : '1',
            'base_unit_price' => $whole ? $concept['base_unit_price'] : $amount,
            'base_total' => $amount,
        ];
    }

    /**
     * Una línea por concepto vendido (WorkOrderSaleBreakdown, compartido con el reparto).
     */
    private function linesFromWorkOrder(WorkOrder $workOrder): array
    {
        return $this->saleBreakdown->concepts($workOrder)
            ->map(fn (array $concept) => [
                'source_type' => $concept['source_type'],
                'source_id' => $concept['source_id'],
                'description' => $concept['description'],
                'quantity' => $concept['quantity'],
                'base_unit_price' => $concept['base_unit_price'],
                'base_total' => $concept['base_total'],
            ])
            ->all();
    }

    /**
     * Totales por tasa y liquidación del IVA sobre precios con IVA incluido.
     */
    private function recalculateTotals(Invoice $invoice): void
    {
        $decimals = (int) config('invoice.decimals', 0);
        $totals = ['0' => '0', '5' => '0', '10' => '0'];
        $discount = '0';

        foreach ($invoice->items()->get() as $item) {
            $rate = (string) $item->tax_rate->value;
            $totals[$rate] = bcadd($totals[$rate], $item->total, 2);
            $discount = bcadd($discount, $item->discount, 2);
        }

        $iva = fn (TaxRate $rate) => Decimal::round(
            bcdiv($totals[(string) $rate->value], $rate->liquidationDivisor(), 8),
            $decimals
        );

        $invoice->update([
            'total_exempt' => $totals['0'],
            'total_5' => $totals['5'],
            'total_10' => $totals['10'],
            'iva_5' => $iva(TaxRate::FIVE),
            'iva_10' => $iva(TaxRate::TEN),
            'discount_total' => $discount,
            'total' => bcadd(bcadd($totals['0'], $totals['5'], 2), $totals['10'], 2),
        ]);
    }

    private function lockDraft(Invoice $invoice): Invoice
    {
        $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

        if ($invoice->status !== InvoiceStatus::DRAFT) {
            throw new InvoiceException(
                'Solo puede modificarse una factura en borrador.',
                422,
                'INVOICE_NOT_EDITABLE'
            );
        }

        return $invoice;
    }

    /**
     * El timbrado indicado, o el único activo y vigente a la fecha.
     */
    private function lockStamp(?int $stampId, $date): InvoiceStamp
    {
        $query = InvoiceStamp::active()->validOn($date)->lockForUpdate();

        $stamps = $stampId ? $query->whereKey($stampId)->get() : $query->get();

        if ($stamps->isEmpty()) {
            throw new InvoiceException(
                'No hay un timbrado activo y vigente para emitir la factura.',
                422,
                'INVOICE_STAMP_NOT_AVAILABLE'
            );
        }

        if ($stamps->count() > 1) {
            throw new InvoiceException(
                'Hay más de un timbrado vigente: indique invoice_stamp_id.',
                422,
                'INVOICE_STAMP_REQUIRED'
            );
        }

        return $stamps->first();
    }

    private function load(Invoice $invoice): Invoice
    {
        return $invoice->refresh()->load(['items', 'workOrder', 'stamp']);
    }
}

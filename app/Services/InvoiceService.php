<?php

namespace App\Services;

use App\Enums\InvoiceCondition;
use App\Enums\InvoiceStatus;
use App\Enums\TaxRate;
use App\Enums\WorkOrderItemStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvoiceException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceStamp;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Facturación de OT completadas (FASE 15). Las líneas salen de lo realmente
 * usado (lo mismo que el cierre congelado); los precios del sistema son sin
 * IVA y la factura los expresa con IVA incluido, en guaraníes.
 */
class InvoiceService
{
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
    private function linesFromWorkOrder(WorkOrder $workOrder): array
    {
        $lines = [];

        foreach ($workOrder->items as $item) {
            $budgetItem = $item->budgetItem;

            if ($item->status === WorkOrderItemStatus::CANCELLED || $budgetItem === null) {
                continue;
            }

            // Los repuestos de catálogo se facturan por lo consumido (abajo).
            if (!$item->isCommissionable() && $budgetItem->part_id !== null) {
                continue;
            }

            $lines[] = [
                'source_type' => $item->getMorphClass(),
                'source_id' => $item->id,
                'description' => $item->description,
                'quantity' => $budgetItem->quantity,
                'base_unit_price' => $budgetItem->unit_price,
                'base_total' => $budgetItem->total,
            ];
        }

        foreach ($workOrder->parts as $line) {
            $net = $line->netQuantity();

            if (bccomp($net, '0', 3) === 0) {
                continue;
            }

            $lines[] = [
                'source_type' => $line->getMorphClass(),
                'source_id' => $line->id,
                'description' => $line->part->name,
                'quantity' => $net,
                'base_unit_price' => $line->unit_price,
                'base_total' => $line->totalPrice(),
            ];
        }

        return $lines;
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

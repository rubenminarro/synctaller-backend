<?php

namespace App\Services;

use App\Enums\CreditNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TaxRate;
use App\Exceptions\InvoiceException;
use App\Models\Invoice;
use App\Models\InvoiceCreditNote;
use App\Models\InvoiceCreditNoteItem;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Format;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Notas de crédito emitidas fuera del sistema y registradas sobre una factura
 * emitida (P10). Por línea y parcial: ninguna línea se acredita por más de lo que
 * le queda y la nota no supera el saldo pendiente (lo cobrado se acredita después
 * de anular el pago). Bajan el saldo; un registro mal cargado se anula con motivo.
 * Orden de bloqueo: factura → nota de crédito.
 */
class CreditNoteService
{
    /**
     * @param array{number: string, stamp_number?: string|null, issue_date: string, reason: string, items: array<int, array{invoice_item_id: int, amount: int|string}>} $data
     */
    public function register(Invoice $invoice, array $data, User $user): InvoiceCreditNote
    {
        return DB::transaction(function () use ($invoice, $data, $user) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== InvoiceStatus::ISSUED) {
                throw new InvoiceException('Solo se registra una nota de crédito sobre una factura emitida.', 422, 'INVOICE_NOT_CREDITABLE');
            }

            if ($data['issue_date'] < $invoice->issue_date->toDateString()) {
                throw new InvoiceException('La nota de crédito no puede ser anterior a la emisión de la factura.', 422, 'CREDIT_NOTE_DATE_BEFORE_INVOICE');
            }

            if (InvoiceCreditNote::registered()->where('number', $data['number'])->exists()) {
                throw new InvoiceException("Ya hay una nota de crédito vigente con el número {$data['number']}.", 409, 'CREDIT_NOTE_DUPLICATE_NUMBER');
            }

            $lines = $this->lines($invoice, $data['items']);
            $total = $lines->reduce(fn (string $sum, array $line) => bcadd($sum, $line['amount'], 2), '0.00');
            $balance = $invoice->balance();

            if (bccomp($total, $balance, 2) > 0) {
                throw new InvoiceException(
                    'La nota de crédito (' . Format::money($total) . ') supera el saldo de la factura (' . Format::money($balance) . '): para acreditar lo cobrado, anule antes el pago.',
                    422,
                    'CREDIT_NOTE_EXCEEDS_BALANCE'
                );
            }

            $creditNote = InvoiceCreditNote::create([
                'invoice_id' => $invoice->id,
                'number' => $data['number'],
                'stamp_number' => $data['stamp_number'] ?? null,
                'issue_date' => $data['issue_date'],
                'reason' => $data['reason'],
                'status' => CreditNoteStatus::REGISTERED,
                'registered_by' => $user->id,
                ...$this->totals($lines, $total),
            ]);

            $lines->each(fn (array $line) => $creditNote->items()->create($line));

            return $this->load($creditNote);
        });
    }

    public function cancel(InvoiceCreditNote $creditNote, string $reason, User $user): InvoiceCreditNote
    {
        return DB::transaction(function () use ($creditNote, $reason, $user) {
            Invoice::whereKey($creditNote->invoice_id)->lockForUpdate()->first();
            $creditNote = InvoiceCreditNote::whereKey($creditNote->id)->lockForUpdate()->firstOrFail();

            if ($creditNote->status !== CreditNoteStatus::REGISTERED) {
                throw new InvoiceException('La nota de crédito ya está anulada.', 422, 'CREDIT_NOTE_NOT_CANCELLABLE');
            }

            $creditNote->update([
                'status' => CreditNoteStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ]);

            return $this->load($creditNote);
        });
    }

    public function load(InvoiceCreditNote $creditNote): InvoiceCreditNote
    {
        return $creditNote->load(['items', 'registrar', 'canceller']);
    }

    /**
     * Lo acreditado de cada línea de la factura por las notas vigentes: [importe con IVA, base sin IVA].
     *
     * @return Collection<int, array{0: string, 1: string}>
     */
    public function creditedByItem(Invoice $invoice): Collection
    {
        return InvoiceCreditNoteItem::query()
            ->whereHas('creditNote', fn ($q) => $q->where('invoice_id', $invoice->id)->where('status', CreditNoteStatus::REGISTERED))
            ->get()
            ->groupBy('invoice_item_id')
            ->map(fn (Collection $items) => [
                $items->reduce(fn (string $sum, $item) => bcadd($sum, (string) $item->amount, 2), '0.00'),
                $items->reduce(fn (string $sum, $item) => bcadd($sum, (string) $item->base_amount, 2), '0.00'),
            ]);
    }

    /**
     * Una línea por línea de la factura acreditada. La base es proporcional a la línea (respeta su descuento); si se
     * acredita todo lo que le queda, es exactamente la base que le queda.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function lines(Invoice $invoice, array $input): Collection
    {
        $items = $invoice->items()->get()->keyBy('id');
        $credited = $this->creditedByItem($invoice);

        return collect($input)->map(function (array $line) use ($items, $credited) {
            /** @var InvoiceItem|null $item */
            $item = $items[$line['invoice_item_id']] ?? null;

            if (!$item) {
                throw new InvoiceException('Alguna línea no es de esta factura.', 422, 'CREDIT_NOTE_ITEM_MISMATCH');
            }

            [$creditedAmount, $creditedBase] = $credited[$item->id] ?? ['0.00', '0.00'];
            $amount = Decimal::of($line['amount'], 2);
            $remaining = bcsub(Decimal::of($item->total, 2), $creditedAmount, 2);

            if (bccomp($amount, $remaining, 2) > 0) {
                throw new InvoiceException(
                    "Lo acreditado de «{$item->description}» supera lo que le queda a la línea (" . Format::money($remaining) . ').',
                    422,
                    'CREDIT_NOTE_EXCEEDS_LINE'
                );
            }

            $base = bccomp($amount, $remaining, 2) === 0
                ? bcsub(Decimal::of($item->base_total, 2), $creditedBase, 2)
                : Decimal::round(bcdiv(bcmul($amount, (string) $item->base_total, 8), (string) $item->total, 8), 2);

            return [
                'invoice_item_id' => $item->id,
                'description' => $item->description,
                'tax_rate' => $item->tax_rate,
                'amount' => $amount,
                'base_amount' => $base,
            ];
        })->values();
    }

    /**
     * Totales por tasa y liquidación del IVA, como en la factura.
     */
    private function totals(Collection $lines, string $total): array
    {
        $decimals = (int) config('invoice.decimals', 0);
        $byRate = fn (TaxRate $rate) => $lines->filter(fn (array $line) => $line['tax_rate'] === $rate)
            ->reduce(fn (string $sum, array $line) => bcadd($sum, $line['amount'], 2), '0.00');
        $iva = fn (TaxRate $rate) => Decimal::round(bcdiv($byRate($rate), $rate->liquidationDivisor(), 8), $decimals);

        return [
            'total' => $total,
            'total_exempt' => $byRate(TaxRate::EXEMPT),
            'total_5' => $byRate(TaxRate::FIVE),
            'total_10' => $byRate(TaxRate::TEN),
            'iva_5' => $iva(TaxRate::FIVE),
            'iva_10' => $iva(TaxRate::TEN),
        ];
    }
}

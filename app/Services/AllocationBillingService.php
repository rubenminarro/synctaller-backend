<?php

namespace App\Services;

use App\Enums\AllocationPortionType;
use App\Enums\AllocationStatus;
use App\Enums\ClaimStatus;
use App\Enums\CreditNoteStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\AllocationException;
use App\Exceptions\ClaimException;
use App\Exceptions\InvoiceException;
use App\Models\Claim;
use App\Models\Invoice;
use App\Models\InvoiceCreditNoteItem;
use App\Models\InvoiceItem;
use App\Models\WorkOrderAllocation;
use App\Models\WorkOrderAllocationLine;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * Lo facturado de un reparto (M9), por porción y neto de notas de crédito (P10):
 * base de las líneas de facturas vigentes menos la base acreditada. Lo usan la
 * generación y la emisión de las facturas por responsable (R8), la corrección
 * del reparto (P6) y la entrega. Solo lectura y sin dependencias de otros
 * Services.
 */
class AllocationBillingService
{
    /**
     * Facturado neto por porción (id de la línea del reparto → base sin IVA).
     *
     * @return Collection<int, string>
     */
    public function billedByLine(WorkOrderAllocation $allocation, bool $issuedOnly = false): Collection
    {
        $statuses = $issuedOnly ? [InvoiceStatus::ISSUED] : InvoiceStatus::currentStatuses();
        $items = InvoiceItem::whereIn('allocation_line_id', $allocation->lines()->pluck('id'))
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', $statuses))
            ->get();
        $credited = $this->creditedByInvoiceItem($items->pluck('id'));

        return $items->groupBy('allocation_line_id')->map(fn (Collection $lines) => $lines->reduce(
            fn (string $sum, InvoiceItem $item) => bcadd($sum, bcsub(Decimal::of($item->base_total, 2), $credited[$item->id] ?? '0.00', 2), 2),
            '0.00'
        ));
    }

    /**
     * Facturas vigentes de una versión con facturado neto (P6 con P10): el detalle de ALLOCATION_HAS_INVOICES.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingInvoices(WorkOrderAllocation $allocation): array
    {
        return Invoice::where('allocation_id', $allocation->id)
            ->whereIn('status', InvoiceStatus::currentStatuses())
            ->with(['items', 'payments', 'creditNotes'])
            ->orderBy('id')
            ->get()
            ->filter(function (Invoice $invoice) {
                $credited = $this->creditedByInvoiceItem($invoice->items->pluck('id'));
                $net = $invoice->items->reduce(fn (string $sum, InvoiceItem $item) => bcadd($sum, bcsub(Decimal::of($item->base_total, 2), $credited[$item->id] ?? '0.00', 2), 2), '0.00');

                return bccomp($net, '0', 2) > 0;
            })
            ->map(fn (Invoice $invoice) => [
                'invoice_id' => $invoice->id,
                'number' => $invoice->number,
                'customer_name' => $invoice->customer_name,
                'status' => $invoice->status->value,
                'has_payments' => bccomp($invoice->paidTotal(), '0', 2) > 0,
                'credited_total' => $invoice->creditedTotal(),
            ])
            ->values()
            ->all();
    }

    /**
     * Facturación del siniestro según el deducible (A): solo con el deducible informado e igual a 0.
     */
    public function ensureDeductibleAllowsInvoicing(Claim $claim): void
    {
        if ($claim->deductible_amount === null) {
            throw new ClaimException('El deducible del siniestro es desconocido: no se factura hasta informarlo.', 422, 'CLAIM_DEDUCTIBLE_UNKNOWN');
        }

        if (bccomp((string) $claim->deductible_amount, '0', 2) > 0) {
            throw new AllocationException(
                'El siniestro tiene deducible: su facturación espera la definición de la modalidad del deducible.',
                422,
                'ALLOCATION_DEDUCTIBLE_MODE_UNDEFINED'
            );
        }
    }

    /**
     * Antes de emitir una factura del reparto: la versión sigue confirmada, el deducible lo permite y ninguna porción
     * queda facturada por más de su importe (R8).
     */
    public function ensureIssuable(Invoice $invoice): void
    {
        $allocation = WorkOrderAllocation::whereKey($invoice->allocation_id)->lockForUpdate()->firstOrFail();

        if ($allocation->status !== AllocationStatus::CONFIRMED) {
            throw new AllocationException(
                'El reparto de esta factura fue sustituido por otra versión: elimine el borrador y facture desde el reparto vigente.',
                409,
                'ALLOCATION_SUPERSEDED'
            );
        }

        $this->ensureDeductibleAllowsInvoicing($allocation->claim);

        $billed = $this->billedByLine($allocation);
        $exceeded = $allocation->lines()->get()->contains(
            fn (WorkOrderAllocationLine $line) => bccomp($billed[$line->id] ?? '0.00', Decimal::of($line->amount, 2), 2) > 0
        );

        if ($exceeded) {
            throw new InvoiceException('Lo facturado supera alguna porción del reparto.', 409, 'INVOICE_EXCEEDS_ALLOCATION');
        }
    }

    /**
     * Para la entrega: el reparto está confirmado y todas sus porciones (salvo las del taller) facturadas y emitidas.
     */
    public function isFullyInvoiced(?WorkOrderAllocation $allocation): bool
    {
        if (!$allocation) {
            return false;
        }

        $billed = $this->billedByLine($allocation, issuedOnly: true);

        return $allocation->lines()->get()
            ->reject(fn (WorkOrderAllocationLine $line) => $line->portion_type === AllocationPortionType::WORKSHOP_ABSORBED)
            ->every(fn (WorkOrderAllocationLine $line) => bccomp($billed[$line->id] ?? '0.00', Decimal::of($line->amount, 2), 2) >= 0);
    }

    /**
     * Para la entrega (A): el siniestro abierto de la recepción con deducible mayor a 0 o desconocido; null si no hay.
     */
    public function claimWithPendingDeductible(int $receptionId): ?Claim
    {
        $claim = Claim::where('reception_id', $receptionId)->where('status', ClaimStatus::OPEN)->first();

        return $claim && ($claim->deductible_amount === null || bccomp((string) $claim->deductible_amount, '0', 2) > 0)
            ? $claim
            : null;
    }

    /**
     * Base acreditada por línea de factura (notas de crédito vigentes).
     *
     * @return Collection<int, string>
     */
    private function creditedByInvoiceItem(Collection $invoiceItemIds): Collection
    {
        return InvoiceCreditNoteItem::whereIn('invoice_item_id', $invoiceItemIds)
            ->whereHas('creditNote', fn ($q) => $q->where('status', CreditNoteStatus::REGISTERED))
            ->get()
            ->groupBy('invoice_item_id')
            ->map(fn (Collection $items) => $items->reduce(fn (string $sum, $item) => bcadd($sum, (string) $item->base_amount, 2), '0.00'));
    }
}

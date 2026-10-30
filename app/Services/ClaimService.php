<?php

namespace App\Services;

use App\Enums\AllocationPortionType;
use App\Enums\AllocationStatus;
use App\Enums\BudgetItemResponsibility;
use App\Enums\BudgetStatus;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\DocumentSeries;
use App\Enums\InsurerStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\ClaimException;
use App\Models\BudgetItem;
use App\Models\Claim;
use App\Models\Insurer;
use App\Models\Invoice;
use App\Models\Reception;
use App\Models\User;
use App\Models\WorkOrderAllocation;
use App\Models\WorkOrderAllocationLine;
use App\Support\Format;
use Illuminate\Support\Facades\DB;

/**
 * Siniestros (M3). Uno vigente (no cancelado) por recepción: uno cancelado
 * queda en el historial y se puede abrir otro. Solo con la recepción abierta y
 * la aseguradora activa. Orden de bloqueo: recepción → siniestro.
 */
class ClaimService
{
    private const INSURED_FIELDS = ['insured_name', 'insured_document', 'insured_phone', 'insured_email'];

    public function __construct(
        protected ReceptionStatusService $receptionStatusService,
        protected DocumentSequenceService $documentSequences
    ) {
    }

    public function create(array $data, User $user): Claim
    {
        return DB::transaction(function () use ($data, $user) {

            $reception = Reception::whereKey($data['reception_id'])->lockForUpdate()->firstOrFail();
            $this->receptionStatusService->ensureOpen($reception);

            if ($reception->currentClaim()->exists()) {
                throw new ClaimException(
                    'La recepción ya tiene un siniestro vigente.',
                    409,
                    'RECEPTION_ALREADY_HAS_CLAIM'
                );
            }

            $insurer = Insurer::findOrFail($data['insurer_id']);

            if ($insurer->status !== InsurerStatus::ACTIVE) {
                throw new ClaimException(
                    'La aseguradora está inactiva y no admite siniestros nuevos.',
                    422,
                    'INSURER_INACTIVE'
                );
            }

            $data = $this->withInsured($data, $reception);
            // M10: la modalidad del deducible por defecto es la de la aseguradora (se cambia según la póliza).
            $data['deductible_billing_mode'] = array_key_exists('deductible_billing_mode', $data)
                ? $data['deductible_billing_mode']
                : $insurer->deductible_billing_mode;
            $this->ensureUniqueClaimNumber($insurer->id, $data['claim_number'] ?? null);

            $claim = $this->documentSequences->create(DocumentSeries::CLAIM, fn (string $code) => Claim::create([
                ...$data,
                'code' => $code,
                'status' => ClaimStatus::OPEN,
                'created_by' => $user->id,
            ]));

            return $this->load($claim);
        });
    }

    public function update(Claim $claim, array $data): Claim
    {
        return DB::transaction(function () use ($claim, $data) {

            Reception::whereKey($claim->reception_id)->lockForUpdate()->first();
            $claim = Claim::whereKey($claim->id)->lockForUpdate()->firstOrFail();

            $this->ensureOpen($claim);
            $this->receptionStatusService->ensureEditable($claim->reception_id);

            if (array_key_exists('claim_number', $data)) {
                $this->ensureUniqueClaimNumber($claim->insurer_id, $data['claim_number'], $claim->id);
            }

            if (array_key_exists('deductible_amount', $data) && !$this->sameAmount($claim->deductible_amount, $data['deductible_amount'])) {
                $this->ensureDeductibleNotInvoiced($claim);
                $this->ensureDeductibleCoversAllocations($claim, $data['deductible_amount']);
            }

            // M10: la modalidad y si el deducible incluye el IVA cambian lo facturado, como el importe.
            $modeChanged = array_key_exists('deductible_billing_mode', $data)
                && $claim->deductible_billing_mode?->value !== $data['deductible_billing_mode'];
            $taxChanged = array_key_exists('deductible_includes_tax', $data)
                && $claim->deductible_includes_tax !== ($data['deductible_includes_tax'] === null ? null : (bool) $data['deductible_includes_tax']);

            if ($modeChanged || $taxChanged) {
                $this->ensureDeductibleNotInvoiced($claim);
            }

            $claim->update($data);

            return $this->load($claim);
        });
    }

    /**
     * Cancelación manual (por ejemplo, la aseguradora rechaza la cobertura y el dueño paga como particular). Con lo
     * cubierto en un reparto confirmado no se cancela (M8): primero se corrige el reparto. El control sobre las
     * facturas de la aseguradora llega con M9.
     */
    public function cancel(Claim $claim, string $reason, User $user): Claim
    {
        return DB::transaction(function () use ($claim, $reason, $user) {

            Reception::whereKey($claim->reception_id)->lockForUpdate()->first();
            $claim = Claim::whereKey($claim->id)->lockForUpdate()->firstOrFail();

            $this->ensureOpen($claim, 'cancelarse', 'CLAIM_NOT_CANCELLABLE');

            $covered = WorkOrderAllocationLine::where('portion_type', AllocationPortionType::INSURER_COVERED)
                ->whereHas('allocation', fn ($q) => $q->where('claim_id', $claim->id)->where('status', AllocationStatus::CONFIRMED))
                ->exists();

            if ($covered) {
                throw new ClaimException(
                    'El siniestro tiene un reparto confirmado con importes a cargo de la aseguradora: corrija el reparto antes de cancelarlo.',
                    422,
                    'CLAIM_HAS_INSURER_ALLOCATIONS'
                );
            }

            $claim->update([
                'status' => ClaimStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ]);

            // Sin siniestro, nada queda a cargo de la aseguradora en los borradores (§5.2). Un presupuesto enviado o
            // aprobado no se toca: se rechaza, se reabre y el cliente vuelve a aprobar (decisión del 2026-10-29).
            BudgetItem::whereHas('budget', fn ($q) => $q->where('reception_id', $claim->reception_id)->where('status', BudgetStatus::DRAFT))
                ->where('responsibility', BudgetItemResponsibility::INSURER)
                ->get()
                ->each(fn (BudgetItem $item) => $item->update(['responsibility' => BudgetItemResponsibility::OWNER]));

            return $this->load($claim);
        });
    }

    /**
     * El deducible no baja de lo ya asignado en los repartos confirmados (M8, decisión del 2026-10-29): primero se
     * corrige el reparto. Desconocido (nulo) tampoco, si ya hay deducible asignado.
     */
    private function ensureDeductibleCoversAllocations(Claim $claim, int|float|string|null $deductible): void
    {
        $allocated = (string) WorkOrderAllocation::where('claim_id', $claim->id)
            ->where('status', AllocationStatus::CONFIRMED)
            ->sum('deductible_amount');

        if (bccomp($allocated, '0', 2) > 0 && ($deductible === null || bccomp((string) $deductible, $allocated, 2) < 0)) {
            throw new ClaimException(
                'El deducible no puede quedar por debajo de lo ya asignado en los repartos confirmados (' . Format::money($allocated) . '): corrija primero el reparto.',
                422,
                'CLAIM_DEDUCTIBLE_BELOW_ALLOCATED'
            );
        }
    }

    /**
     * Con una factura vigente a la aseguradora el deducible no cambia (M9, §5.2): antes se elimina el borrador o se
     * acredita la factura emitida.
     */
    private function ensureDeductibleNotInvoiced(Claim $claim): void
    {
        $insurerClientId = $claim->insurer()->withTrashed()->value('client_id');
        $invoiced = Invoice::whereIn('status', InvoiceStatus::currentStatuses())
            ->where('client_id', $insurerClientId)
            ->whereHas('allocation', fn ($q) => $q->where('claim_id', $claim->id))
            ->exists();

        if ($invoiced) {
            throw new ClaimException(
                'El siniestro tiene una factura vigente a la aseguradora: elimine el borrador o acredite la factura antes de cambiar el deducible.',
                422,
                'CLAIM_DEDUCTIBLE_LOCKED'
            );
        }
    }

    private function sameAmount(int|float|string|null $current, int|float|string|null $new): bool
    {
        return $current === null || $new === null
            ? $current === $new
            : bccomp((string) $current, (string) $new, 2) === 0;
    }

    public function load(Claim $claim): Claim
    {
        return $claim->load(['reception.client', 'reception.vehicle.brand', 'reception.vehicle.vehicleModel', 'insurer.client', 'creator', 'canceller']);
    }

    /**
     * En un siniestro propio, sin datos del asegurado se copian los del cliente de la recepción (decisión del
     * 2026-10-29). En uno de tercero el nombre llega validado como obligatorio.
     */
    private function withInsured(array $data, Reception $reception): array
    {
        $hasInsured = collect(self::INSURED_FIELDS)->contains(fn (string $field) => filled($data[$field] ?? null));

        if ($data['claim_type'] !== ClaimType::OWN->value || $hasInsured) {
            return $data;
        }

        $client = $reception->client;

        return [
            ...$data,
            'insured_client_id' => $client->id,
            'insured_name' => $client->display_name,
            'insured_document' => $client->document_number,
            'insured_phone' => $client->phone,
            'insured_email' => $client->email,
        ];
    }

    private function ensureUniqueClaimNumber(int $insurerId, ?string $claimNumber, ?int $ignoreId = null): void
    {
        if (blank($claimNumber)) {
            return;
        }

        $exists = Claim::where('insurer_id', $insurerId)
            ->where('claim_number', $claimNumber)
            ->where('status', '!=', ClaimStatus::CANCELLED)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new ClaimException(
                "La aseguradora ya tiene un siniestro vigente con el número {$claimNumber}.",
                409,
                'CLAIM_NUMBER_DUPLICATE'
            );
        }
    }

    private function ensureOpen(Claim $claim, string $action = 'modificarse', string $errorCode = 'CLAIM_NOT_EDITABLE'): void
    {
        if ($claim->status !== ClaimStatus::OPEN) {
            throw new ClaimException(
                'El siniestro está ' . mb_strtolower($claim->status->label()) . " y no puede {$action}.",
                422,
                $errorCode
            );
        }
    }
}

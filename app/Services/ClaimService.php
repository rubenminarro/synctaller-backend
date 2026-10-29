<?php

namespace App\Services;

use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\DocumentSeries;
use App\Enums\InsurerStatus;
use App\Exceptions\ClaimException;
use App\Models\Claim;
use App\Models\Insurer;
use App\Models\Reception;
use App\Models\User;
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

            $claim->update($data);

            return $this->load($claim);
        });
    }

    /**
     * Cancelación manual (por ejemplo, la aseguradora rechaza la cobertura y el dueño paga como particular). Los
     * controles sobre el reparto y las facturas de la aseguradora llegan con M8 y M9.
     */
    public function cancel(Claim $claim, string $reason, User $user): Claim
    {
        return DB::transaction(function () use ($claim, $reason, $user) {

            Reception::whereKey($claim->reception_id)->lockForUpdate()->first();
            $claim = Claim::whereKey($claim->id)->lockForUpdate()->firstOrFail();

            $this->ensureOpen($claim, 'cancelarse', 'CLAIM_NOT_CANCELLABLE');

            $claim->update([
                'status' => ClaimStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ]);

            return $this->load($claim);
        });
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

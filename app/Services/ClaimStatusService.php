<?php

namespace App\Services;

use App\Enums\ClaimStatus;
use App\Models\Claim;
use App\Models\Reception;

/**
 * Estado del siniestro según la recepción, dentro de la transacción del evento:
 * se cierra al entregar, se reabre al anular la entrega y se cancela con la
 * recepción. Sin dependencias para que lo usen ReceptionStatusService y
 * VehicleDeliveryService (ClaimService depende de ellos).
 */
class ClaimStatusService
{
    public function receptionDelivered(Reception $reception): void
    {
        $this->currentClaim($reception)?->update(['status' => ClaimStatus::CLOSED, 'closed_at' => now()]);
    }

    public function deliveryCancelled(Reception $reception): void
    {
        $claim = $this->currentClaim($reception);

        if ($claim?->status === ClaimStatus::CLOSED) {
            $claim->update(['status' => ClaimStatus::OPEN, 'closed_at' => null]);
        }
    }

    public function receptionCancelled(Reception $reception, string $reason, int $userId): void
    {
        $this->currentClaim($reception)?->update([
            'status' => ClaimStatus::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $userId,
            'cancellation_reason' => "Recepción cancelada: {$reason}",
        ]);
    }

    private function currentClaim(Reception $reception): ?Claim
    {
        return Claim::where('reception_id', $reception->id)
            ->where('status', '!=', ClaimStatus::CANCELLED)
            ->lockForUpdate()
            ->first();
    }
}

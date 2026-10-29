<?php

namespace App\Services;

use App\Enums\BudgetStatus;
use App\Enums\ClientNotificationType;
use App\Enums\ReceptionStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\ReceptionException;
use App\Models\Budget;
use App\Models\Reception;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Estado de la recepción derivado del flujo (no se asigna a mano, salvo la
 * cancelación y la entrega):
 *
 *   alguna OT in_progress|paused          → repair      (En reparación)
 *   alguna OT pending                     → in_progress (En proceso)
 *   OT completadas (el resto canceladas)  → completed   (Terminado: lista para entregar)
 *   sin OT vigentes: con presupuesto      → in_progress
 *                    sin presupuesto      → pending
 *
 * delivered (VehicleDeliveryService) y cancelled (cancel()) no se recalculan.
 * La regla está duplicada en SQL en la migración 000045.
 */
class ReceptionStatusService
{
    public function __construct(
        protected ClientNotificationService $clientNotificationService,
        protected ClaimStatusService $claimStatusService
    ) {
    }

    /**
     * Recalcula el estado. Llamar dentro de la transacción del evento
     * (orden de bloqueo: OT → recepción).
     */
    public function sync(Reception|int $reception): Reception
    {
        $reception = Reception::whereKey($reception instanceof Reception ? $reception->id : $reception)
            ->lockForUpdate()
            ->firstOrFail();

        if ($reception->status->isClosed()) {
            return $reception;
        }

        $status = $this->derive($reception);

        if ($status !== $reception->status) {
            $reception->update(['status' => $status]);

            // Vehículo listo: un aviso por recepción (si vuelve a reparación no se repite).
            if ($status === ReceptionStatus::COMPLETED) {
                $this->clientNotificationService->notify(
                    ClientNotificationType::VEHICLE_READY,
                    $reception,
                    $reception->client,
                    "vehicle_ready:{$reception->id}"
                );
            }
        }

        return $reception;
    }

    /**
     * Diagnósticos, presupuestos y OT solo sobre recepciones abiertas.
     */
    public function ensureOpen(Reception|int $reception): void
    {
        $reception = $reception instanceof Reception ? $reception : Reception::findOrFail($reception);

        if ($reception->status->isClosed()) {
            throw new ReceptionException(
                "La recepción está {$this->lower($reception->status)} y no admite nuevos trabajos.",
                422,
                'RECEPTION_CLOSED'
            );
        }
    }

    /**
     * Una recepción cerrada (entregada o cancelada) no se modifica: ni sus datos, ni sus fotos, ni sus diagnósticos o
     * presupuestos.
     */
    public function ensureEditable(Reception|int $reception): void
    {
        $reception = $reception instanceof Reception ? $reception : Reception::findOrFail($reception);

        if ($reception->status->isClosed()) {
            throw new ReceptionException(
                "La recepción está {$this->lower($reception->status)} y no puede modificarse.",
                422,
                'RECEPTION_CLOSED'
            );
        }
    }

    /**
     * Cancelación manual (por ejemplo, el cliente desiste). Solo sin OT vigentes. Cancela también los presupuestos en
     * borrador o enviados: ya no pueden aprobarse.
     */
    public function cancel(Reception $reception, string $reason, User $user): Reception
    {
        return DB::transaction(function () use ($reception, $reason, $user) {

            $reception = Reception::whereKey($reception->id)->lockForUpdate()->firstOrFail();

            if ($reception->status->isClosed()) {
                throw new ReceptionException(
                    "La recepción ya está {$this->lower($reception->status)} y no puede cancelarse.",
                    422,
                    'RECEPTION_NOT_CANCELLABLE'
                );
            }

            $hasActiveWorkOrders = $reception->workOrders()
                ->where('status', '!=', WorkOrderStatus::CANCELLED)
                ->exists();

            if ($hasActiveWorkOrders) {
                throw new ReceptionException(
                    'La recepción tiene órdenes de trabajo vigentes: cancélelas antes de cancelar la recepción.',
                    422,
                    'RECEPTION_HAS_ACTIVE_WORK_ORDERS'
                );
            }

            $reception->update([
                'status' => ReceptionStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ]);

            // El siniestro vigente se cancela con la recepción.
            $this->claimStatusService->receptionCancelled($reception, $reason, $user->id);

            // Uno por uno para que cada cambio quede en la auditoría.
            $reception->budgets()
                ->whereIn('status', [BudgetStatus::DRAFT, BudgetStatus::SENT])
                ->get()
                ->each(fn (Budget $budget) => $budget->update(['status' => BudgetStatus::CANCELLED]));

            return $reception;
        });
    }

    private function derive(Reception $reception): ReceptionStatus
    {
        $statuses = $reception->workOrders()->pluck('status')
            ->map(fn ($status) => $status instanceof WorkOrderStatus ? $status : WorkOrderStatus::from($status));

        $has = fn (WorkOrderStatus ...$wanted) => $statuses->contains(fn ($s) => in_array($s, $wanted, true));

        return match (true) {
            $has(WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::PAUSED) => ReceptionStatus::REPAIR,
            $has(WorkOrderStatus::PENDING) => ReceptionStatus::IN_PROGRESS,
            $has(WorkOrderStatus::COMPLETED) => ReceptionStatus::COMPLETED,
            $reception->budgets()->exists() => ReceptionStatus::IN_PROGRESS,
            default => ReceptionStatus::PENDING,
        };
    }

    private function lower(ReceptionStatus $status): string
    {
        return mb_strtolower($status->label());
    }
}

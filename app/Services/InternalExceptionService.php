<?php

namespace App\Services;

use App\Enums\BudgetStatus;
use App\Enums\InternalExceptionType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InternalExceptionException;
use App\Models\Budget;
use App\Models\InternalException;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Excepciones internas del flujo de siniestros (M7, decisión C). Solo dos tipos:
 * start_without_authorization (sobre un presupuesto con líneas de la aseguradora
 * todavía sin autorizar; la consume el alta de la OT) y workshop_absorption
 * (sobre una OT de un siniestro, con importe; la consume el reparto en M8).
 * Ninguna sube lo que paga la aseguradora ni lo aceptado por el dueño. Una sin
 * usar por sujeto y tipo. Orden de bloqueo: presupuesto u OT → excepción.
 */
class InternalExceptionService
{
    public function __construct(
        protected OwnerShareService $ownerShare,
        protected ReceptionStatusService $receptionStatusService
    ) {
    }

    /**
     * @param array{type: string, subject_id: int, amount?: string|int|float|null, reason: string} $data
     */
    public function grant(array $data, User $user): InternalException
    {
        $type = InternalExceptionType::from($data['type']);

        return DB::transaction(function () use ($type, $data, $user) {
            $subject = match ($type) {
                InternalExceptionType::START_WITHOUT_AUTHORIZATION => $this->startSubject((int) $data['subject_id']),
                InternalExceptionType::WORKSHOP_ABSORPTION => $this->absorptionSubject((int) $data['subject_id']),
            };

            $pending = InternalException::where('type', $type)
                ->whereMorphedTo('subject', $subject)
                ->unconsumed()
                ->lockForUpdate()
                ->exists();

            if ($pending) {
                throw new InternalExceptionException(
                    'Ya hay una excepción de este tipo sin usar para este documento.',
                    409,
                    'INTERNAL_EXCEPTION_ALREADY_GRANTED'
                );
            }

            $exception = InternalException::create([
                'type' => $type,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'amount' => $type === InternalExceptionType::WORKSHOP_ABSORPTION ? $data['amount'] : null,
                'reason' => $data['reason'],
                'granted_by' => $user->id,
                'granted_at' => now(),
            ]);

            return $this->load($exception);
        });
    }

    /**
     * La excepción con la que se crea la OT del presupuesto: start_without_authorization, de ese presupuesto y sin
     * usar. Llamar dentro de la transacción del alta, con el presupuesto bloqueado.
     */
    public function forWorkOrderStart(int $id, Budget $budget): InternalException
    {
        $exception = InternalException::whereKey($id)->lockForUpdate()->first();

        if (!$exception
            || $exception->type !== InternalExceptionType::START_WITHOUT_AUTHORIZATION
            || $exception->subject_type !== $budget->getMorphClass()
            || (int) $exception->subject_id !== $budget->id) {
            $this->notApplicable('La excepción no es para iniciar sin autorización este presupuesto.');
        }

        if ($exception->consumed_at !== null) {
            throw new InternalExceptionException(
                'La excepción ya se usó.',
                409,
                'INTERNAL_EXCEPTION_ALREADY_CONSUMED'
            );
        }

        return $exception;
    }

    /**
     * Registra la operación que la usó (una sola vez).
     */
    public function consume(InternalException $exception, Model $consumedBy): void
    {
        $exception->update([
            'consumed_at' => now(),
            'consumed_by_type' => $consumedBy->getMorphClass(),
            'consumed_by_id' => $consumedBy->getKey(),
        ]);
    }

    public function notApplicable(string $message): never
    {
        throw new InternalExceptionException($message, 422, 'INTERNAL_EXCEPTION_NOT_APPLICABLE');
    }

    public function load(InternalException $exception): InternalException
    {
        return $exception->load(['subject', 'consumedBy', 'grantor']);
    }

    /**
     * Presupuesto enviado o aprobado, sin OT, con líneas de la aseguradora y un siniestro abierto sin su autorización.
     */
    private function startSubject(int $budgetId): Budget
    {
        $budget = Budget::whereKey($budgetId)->lockForUpdate()->first();

        if (!$budget) {
            $this->invalidSubject('El presupuesto no existe.');
        }

        if (!in_array($budget->status, [BudgetStatus::SENT, BudgetStatus::APPROVED], true)) {
            $this->invalidSubject('Solo se otorga sobre un presupuesto enviado o aprobado.');
        }

        if ($budget->workOrder()->exists()) {
            $this->invalidSubject('El presupuesto ya tiene una orden de trabajo.');
        }

        $this->receptionStatusService->ensureOpen($budget->reception_id);

        if (!$this->ownerShare->hasInsurerItems($budget)) {
            $this->invalidSubject('El presupuesto no tiene líneas a cargo de la aseguradora.');
        }

        $claim = $this->ownerShare->openClaim($budget->reception_id);

        if (!$claim) {
            $this->invalidSubject('La recepción del presupuesto no tiene un siniestro abierto.');
        }

        if ($this->ownerShare->currentAuthorization($budget, $claim)) {
            $this->invalidSubject('El presupuesto ya tiene la autorización de la aseguradora.');
        }

        return $budget;
    }

    /**
     * OT no cancelada de un presupuesto con líneas de la aseguradora y un siniestro abierto.
     */
    private function absorptionSubject(int $workOrderId): WorkOrder
    {
        $workOrder = WorkOrder::whereKey($workOrderId)->lockForUpdate()->first();

        if (!$workOrder) {
            $this->invalidSubject('La orden de trabajo no existe.');
        }

        if ($workOrder->status === WorkOrderStatus::CANCELLED) {
            $this->invalidSubject('La orden de trabajo está cancelada.');
        }

        if (!$this->ownerShare->hasInsurerItems($workOrder->budget) || !$this->ownerShare->openClaim($workOrder->reception_id)) {
            $this->invalidSubject('La absorción del taller solo se otorga en una OT de un siniestro abierto con líneas a cargo de la aseguradora.');
        }

        return $workOrder;
    }

    private function invalidSubject(string $message): never
    {
        throw new InternalExceptionException($message, 422, 'INTERNAL_EXCEPTION_SUBJECT_INVALID');
    }
}

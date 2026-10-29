<?php

namespace App\Services;

use App\Enums\BudgetItemType;
use App\Enums\BudgetStatus;
use App\Enums\DocumentSeries;
use App\Enums\WorkOrderItemStatus;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\WorkOrderException;
use App\Models\Budget;
use App\Models\InternalException;
use App\Models\Mechanic;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Support\Format;
use Illuminate\Support\Facades\DB;

class WorkOrderService
{
    public function __construct(
        protected WorkOrderPartService $workOrderPartService,
        protected WorkOrderCommissionService $commissionService,
        protected WorkOrderClosingService $closingService,
        protected MaintenanceService $maintenanceService,
        protected ReceptionStatusService $receptionStatusService,
        protected InternalNotificationService $internalNotificationService,
        protected DocumentSequenceService $documentSequences,
        protected OwnerShareService $ownerShare,
        protected InternalExceptionService $internalExceptions
    ) {
    }

    public function createFromBudget(
        Budget $budget,
        Mechanic $mechanic,
        int $createdBy,
        ?int $internalExceptionId = null
    ): WorkOrder {

        return DB::transaction(function () use ($budget, $mechanic, $createdBy, $internalExceptionId) {

            $budget->loadMissing('items');

            if ($budget->status !== BudgetStatus::APPROVED) {
                throw new WorkOrderException(
                    'El presupuesto debe estar aprobado para crear una orden de trabajo.',
                    422,
                    'WORK_ORDER_BUDGET_NOT_APPROVED'
                );
            }

            if ($budget->items->isEmpty()) {
                throw new WorkOrderException(
                    'El presupuesto debe tener al menos un item para crear una orden de trabajo.',
                    422,
                    'WORK_ORDER_BUDGET_WITHOUT_ITEMS'
                );
            }

            if ($budget->workOrder()->exists()) {
                throw new WorkOrderException(
                    'El presupuesto ya tiene una orden de trabajo.',
                    422,
                    'WORK_ORDER_ALREADY_EXISTS'
                );
            }

            if (!$mechanic->exists) {
                throw new WorkOrderException(
                    'El mecánico seleccionado no existe.',
                    422,
                    'WORK_ORDER_MECHANIC_NOT_FOUND'
                );
            }

            $this->receptionStatusService->ensureOpen($budget->reception_id);

            $exception = $this->ensureInsurerRequirements($budget, $internalExceptionId);

            $workOrder = $this->documentSequences->create(DocumentSeries::WORK_ORDER, fn (string $code) => WorkOrder::create([
                'reception_id' => $budget->reception_id,
                'budget_id' => $budget->id,
                'mechanic_id' => $mechanic->id,
                'created_by' => $createdBy,
                'code' => $code,
                'status' => WorkOrderStatus::PENDING,
                'started_at' => null,
                'completed_at' => null,
                'notes' => null,
            ]));

            foreach ($budget->items as $budgetItem) {
                $workOrderItem = $workOrder->items()->create([
                    'budget_item_id' => $budgetItem->id,
                    'type' => WorkOrderItemType::from(
                        $budgetItem->type->value
                    ),
                    'description' => $budgetItem->description,
                    'quantity' => $budgetItem->quantity,
                    'status' => WorkOrderItemStatus::PENDING,
                    'notes' => null,
                ]);

                // Mano de obra / servicio: asignado al responsable (100%).
                $this->commissionService->assignResponsible($workOrder, $workOrderItem);

                // Repuesto presupuestado con vínculo al catálogo: se reserva stock.
                if ($budgetItem->type === BudgetItemType::PART && $budgetItem->part_id) {
                    $this->workOrderPartService->createFromBudgetItem(
                        $workOrder,
                        $workOrderItem,
                        $budgetItem,
                        $createdBy
                    );
                }
            }

            if ($exception) {
                $this->internalExceptions->consume($exception, $workOrder);
            }

            // El estado de la recepción se deriva de sus OT.
            $this->receptionStatusService->sync($workOrder->reception_id);

            return $workOrder->load([
                'reception',
                'budget',
                'mechanic',
                'creator',
                'items',
                'items.budgetItem',
                'items.mechanicAssignments.mechanic.user',
                'parts.part',
            ]);
        });
    }

    /**
     * Siniestro (M7): con líneas a cargo de la aseguradora, la OT exige su autorización vigente (o la excepción
     * start_without_authorization del presupuesto) y que la última aceptación del dueño cubra su parte actual. La
     * excepción no reemplaza la aceptación. Sin líneas de la aseguradora (particular) no cambia nada. Devuelve la
     * excepción a consumir. Orden de bloqueo: presupuesto → autorización → excepción.
     */
    private function ensureInsurerRequirements(Budget $budget, ?int $exceptionId): ?InternalException
    {
        if (!$this->ownerShare->hasInsurerItems($budget)) {
            if ($exceptionId) {
                $this->internalExceptions->notApplicable('El presupuesto no tiene líneas a cargo de la aseguradora: no necesita la excepción.');
            }

            return null;
        }

        Budget::whereKey($budget->id)->lockForUpdate()->first();

        $claim = $this->ownerShare->openClaim($budget->reception_id);
        $exception = $exceptionId ? $this->internalExceptions->forWorkOrderStart($exceptionId, $budget) : null;

        if ($claim && !$this->ownerShare->currentAuthorization($budget, $claim)) {
            if (!$exception) {
                throw new WorkOrderException(
                    'El presupuesto tiene líneas a cargo de la aseguradora sin su autorización: regístrela o use la excepción para iniciar sin autorización.',
                    422,
                    'WORK_ORDER_INSURER_AUTHORIZATION_REQUIRED'
                );
            }
        } elseif ($exception) {
            $this->internalExceptions->notApplicable($claim
                ? 'El presupuesto ya tiene la autorización de la aseguradora: no necesita la excepción.'
                : 'La recepción no tiene un siniestro abierto: no necesita la excepción.');
        }

        $accepted = (string) ($this->ownerShare->lastAcceptance($budget)?->owner_amount ?? '0');
        $current = $this->ownerShare->budgetOwnerAmount($budget);

        if (bccomp($current, $accepted, 2) > 0) {
            throw new WorkOrderException(
                'La parte del dueño (' . Format::money($current) . ') supera lo que aceptó (' . Format::money($accepted) . '): registre una re-aceptación.',
                422,
                'WORK_ORDER_CLIENT_ACCEPTANCE_OUTDATED'
            );
        }

        return $exception;
    }

    public function start(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {

            $workOrder->refresh();

            if ($workOrder->status !== WorkOrderStatus::PENDING) {
                throw new WorkOrderException(
                    'La orden de trabajo solo puede iniciarse cuando está pendiente.',
                    409,
                    'WORK_ORDER_NOT_STARTABLE'
                );
            }

            $workOrder->update([
                'status' => WorkOrderStatus::IN_PROGRESS,
                'started_at' => $workOrder->started_at ?? now(),
            ]);

            // El estado de la recepción se deriva de sus OT.
            $this->receptionStatusService->sync($workOrder->reception_id);

            return $workOrder->load([
                'reception',
                'budget',
                'mechanic',
                'creator',
                'items',
                'items.budgetItem',
                'items.mechanicAssignments.mechanic.user',
                'parts.part',
            ]);
        });
    }

    public function pause(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {

            $workOrder->refresh();

            if ($workOrder->status !== WorkOrderStatus::IN_PROGRESS) {
                throw new WorkOrderException(
                    'La orden de trabajo solo puede pausarse cuando está en progreso.',
                    422,
                    'WORK_ORDER_NOT_PAUSABLE'
                );
            }

            $workOrder->update([
                'status' => WorkOrderStatus::PAUSED,
            ]);

            // El estado de la recepción se deriva de sus OT.
            $this->receptionStatusService->sync($workOrder->reception_id);

            return $workOrder->load([
                'reception',
                'budget',
                'mechanic',
                'creator',
                'items',
                'items.budgetItem',
                'items.mechanicAssignments.mechanic.user',
                'parts.part',
            ]);
        });
    }

    public function resume(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {

            $workOrder->refresh();

            if ($workOrder->status !== WorkOrderStatus::PAUSED) {
                throw new WorkOrderException(
                    'La orden de trabajo solo puede reanudarse cuando está pausada.',
                    422,
                    'WORK_ORDER_NOT_RESUMABLE'
                );
            }

            $workOrder->update([
                'status' => WorkOrderStatus::IN_PROGRESS,
            ]);

            // El estado de la recepción se deriva de sus OT.
            $this->receptionStatusService->sync($workOrder->reception_id);

            return $workOrder->load([
                'reception',
                'budget',
                'mechanic',
                'creator',
                'items',
                'items.budgetItem',
                'items.mechanicAssignments.mechanic.user',
                'parts.part',
            ]);
        });
    }

    public function complete(WorkOrder $workOrder, ?int $closedBy = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $closedBy) {

            $workOrder->refresh();

            if ($workOrder->status !== WorkOrderStatus::IN_PROGRESS) {
                throw new WorkOrderException(
                    'La orden de trabajo solo puede completarse cuando está en progreso.',
                    422,
                    'WORK_ORDER_NOT_COMPLETABLE'
                );
            }

            $items = $workOrder->items()->get();

            if ($items->isEmpty()) {
                throw new WorkOrderException(
                    'La orden de trabajo debe tener al menos un item para poder completarse.',
                    422,
                    'WORK_ORDER_WITHOUT_ITEMS'
                );
            }

            $hasPendingItems = $items->contains(function ($item) {
                return in_array($item->status, [
                    WorkOrderItemStatus::PENDING,
                    WorkOrderItemStatus::IN_PROGRESS,
                ], true);
            });

            if ($hasPendingItems) {
                throw new WorkOrderException(
                    'La orden de trabajo no puede completarse porque tiene items pendientes.',
                    422,
                    'WORK_ORDER_HAS_PENDING_ITEMS'
                );
            }

            $this->workOrderPartService->ensureResolved($workOrder);

            $workOrder->update([
                'status' => WorkOrderStatus::COMPLETED,
                'completed_at' => now(),
            ]);

            // Cierre económico: congela venta, costos, margen y comisiones.
            $this->closingService->close($workOrder, $closedBy);

            // Mantenimiento preventivo: los servicios hechos actualizan los planes del vehículo.
            $this->maintenanceService->registerFromWorkOrder($workOrder);

            // El estado de la recepción se deriva de sus OT.
            $this->receptionStatusService->sync($workOrder->reception_id);

            // Aviso interno: lista para facturar.
            $this->internalNotificationService->workOrderCompleted($workOrder);

            return $workOrder->load([
                'reception',
                'budget',
                'mechanic',
                'creator',
                'items',
                'items.budgetItem',
                'items.mechanicAssignments.mechanic.user',
                'parts.part',
            ]);
        });
    }

    public function cancel(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {

            $workOrder->refresh();

            if ($workOrder->status !== WorkOrderStatus::PENDING) {
                throw new WorkOrderException(
                    'La orden de trabajo solo puede cancelarse cuando está pendiente.',
                    422,
                    'WORK_ORDER_NOT_CANCELLABLE'
                );
            }

            $workOrder->update([
                'status' => WorkOrderStatus::CANCELLED,
                'started_at' => null,
                'completed_at' => null,
            ]);

            $workOrder->items()
                ->whereIn('status', [
                    WorkOrderItemStatus::PENDING,
                    WorkOrderItemStatus::IN_PROGRESS,
                ])
                ->update([
                    'status' => WorkOrderItemStatus::CANCELLED,
                ]);

            $this->workOrderPartService->cancelOpenLines(
                $workOrder->parts()->open()->get()
            );

            // El estado de la recepción se deriva de sus OT.
            $this->receptionStatusService->sync($workOrder->reception_id);

            return $workOrder->load([
                'reception',
                'budget',
                'mechanic',
                'creator',
                'items',
                'items.budgetItem',
                'items.mechanicAssignments.mechanic.user',
                'parts.part',
            ]);
        });
    }

    public function startItem(
        WorkOrder $workOrder,
        WorkOrderItem $item
    ): WorkOrderItem {
        
        return DB::transaction(function () use ($workOrder, $item) {

            $workOrder->refresh();
            $item->refresh();

            $this->ensureItemBelongsToWorkOrder($workOrder, $item);

            if ($workOrder->status !== WorkOrderStatus::IN_PROGRESS) {
                throw new WorkOrderException(
                    'No se puede iniciar un item si la orden de trabajo no está en progreso.',
                    422,
                    'WORK_ORDER_NOT_IN_PROGRESS'
                );
            }

            if ($item->status !== WorkOrderItemStatus::PENDING) {
                throw new WorkOrderException(
                    'El item solo puede iniciarse cuando está pendiente.',
                    422,
                    'WORK_ORDER_ITEM_NOT_STARTABLE'
                );
            }

            $item->update([
                'status' => WorkOrderItemStatus::IN_PROGRESS,
            ]);

            return $item->refresh();
        });
    }

    public function completeItem(
        WorkOrder $workOrder,
        WorkOrderItem $item
    ): WorkOrderItem {
        return DB::transaction(function () use ($workOrder, $item) {

            $workOrder->refresh();
            $item->refresh();

            $this->ensureItemBelongsToWorkOrder($workOrder, $item);

            if ($workOrder->status !== WorkOrderStatus::IN_PROGRESS) {
                throw new WorkOrderException(
                    'No se puede completar un item si la orden de trabajo no está en progreso.',
                    422,
                    'WORK_ORDER_NOT_IN_PROGRESS'
                );
            }

            if ($item->status !== WorkOrderItemStatus::IN_PROGRESS) {
                throw new WorkOrderException(
                    'El item solo puede completarse cuando está en progreso.',
                    422,
                    'WORK_ORDER_ITEM_NOT_COMPLETABLE'
                );
            }

            $item->update([
                'status' => WorkOrderItemStatus::COMPLETED,
            ]);

            return $item->refresh();
        });
    }

    public function cancelItem(
        WorkOrder $workOrder,
        WorkOrderItem $item
    ): WorkOrderItem {
        return DB::transaction(function () use ($workOrder, $item) {

            $workOrder->refresh();
            $item->refresh();

            $this->ensureItemBelongsToWorkOrder($workOrder, $item);

            if ($workOrder->status !== WorkOrderStatus::IN_PROGRESS) {
                throw new WorkOrderException(
                    'No se puede cancelar un item si la orden de trabajo no está en progreso.',
                    422,
                    'WORK_ORDER_NOT_IN_PROGRESS'
                );
            }

            if ($item->status !== WorkOrderItemStatus::PENDING) {
                throw new WorkOrderException(
                    'El item solo puede cancelarse cuando está pendiente.',
                    422,
                    'WORK_ORDER_ITEM_NOT_CANCELLABLE'
                );
            }

            $item->update([
                'status' => WorkOrderItemStatus::CANCELLED,
            ]);

            $this->workOrderPartService->cancelOpenLines(
                $item->parts()->open()->get()
            );

            return $item->refresh();
        });
    }

    private function ensureItemBelongsToWorkOrder(
        WorkOrder $workOrder,
        WorkOrderItem $item
    ): void {

        if ((int) $item->work_order_id !== (int) $workOrder->id) {
            throw new WorkOrderException(
                'El item no pertenece a la orden de trabajo indicada.',
                422,
                'WORK_ORDER_ITEM_NOT_BELONGING'
            );
        }
    }

    private function ensureEditable(WorkOrder $workOrder): void
    {
        if ($workOrder->status !== WorkOrderStatus::PENDING) {
            throw new WorkOrderException(
                'La orden de trabajo solo puede editarse cuando está pendiente.',
                422,
                'WORK_ORDER_NOT_EDITABLE'
            );
        }
    }

    public function update(WorkOrder $workOrder, array $data): WorkOrder
    {
        $this->ensureEditable($workOrder);

        return DB::transaction(function () use ($workOrder, $data) {

            $previousMechanicId = (int) $workOrder->mechanic_id;

            $workOrder->update([
                'mechanic_id' => $data['mechanic_id'] ?? $workOrder->mechanic_id,
                'notes' => $data['notes'] ?? $workOrder->notes,
            ]);

            $this->commissionService->reassignResponsible($workOrder, $previousMechanicId);

            return $workOrder->fresh();
        });
    }

}
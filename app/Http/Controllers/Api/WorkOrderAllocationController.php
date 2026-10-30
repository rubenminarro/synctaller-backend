<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAllocationInvoiceRequest;
use App\Http\Requests\UpdateWorkOrderAllocationRequest;
use App\Http\Resources\ShowInvoiceResource;
use App\Http\Resources\WorkOrderAllocationResource;
use App\Models\WorkOrder;
use App\Models\WorkOrderAllocation;
use App\Services\AllocationService;
use App\Services\InvoiceService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Reparto económico de la OT de un siniestro (M8), en
 * work-orders/{workOrder}/allocations (scopeBindings): generar el borrador,
 * editarlo, confirmarlo o descartarlo, y consultar las versiones.
 */
class WorkOrderAllocationController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        protected AllocationService $allocationService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:work-order-allocations.index', only: ['index']),
            new Middleware('permission:work-order-allocations.store', only: ['store']),
            new Middleware('permission:work-order-allocations.show', only: ['show']),
            new Middleware('permission:work-order-allocations.update', only: ['update']),
            new Middleware('permission:work-order-allocations.confirm', only: ['confirm']),
            new Middleware('permission:work-order-allocations.destroy', only: ['destroy']),
            new Middleware('permission:invoices.store', only: ['invoice']),
        ];
    }

    /**
     * Factura de un responsable desde el reparto confirmado (M9).
     */
    public function invoice(StoreAllocationInvoiceRequest $request, WorkOrder $workOrder, WorkOrderAllocation $allocation, InvoiceService $invoiceService)
    {
        $invoice = $invoiceService->generateForAllocation($allocation, (int) $request->validated()['client_id'], $request->user());

        return $this->successResponse(
            'Factura generada en borrador correctamente.',
            new ShowInvoiceResource($invoice->load(['items', 'workOrder', 'payments', 'creditNotes'])),
            201
        );
    }

    public function index(WorkOrder $workOrder)
    {
        return $this->successResponse(
            'Repartos de la orden de trabajo obtenidos correctamente.',
            WorkOrderAllocationResource::collection(
                $workOrder->allocations()->get()->map(fn (WorkOrderAllocation $allocation) => $this->allocationService->load($allocation))
            ),
            200
        );
    }

    public function store(Request $request, WorkOrder $workOrder)
    {
        return $this->successResponse(
            'Reparto generado en borrador correctamente.',
            new WorkOrderAllocationResource($this->allocationService->generate($workOrder, $request->user())),
            201
        );
    }

    public function show(WorkOrder $workOrder, WorkOrderAllocation $allocation)
    {
        return $this->successResponse(
            'Reparto obtenido correctamente.',
            new WorkOrderAllocationResource($this->allocationService->load($allocation)),
            200
        );
    }

    public function update(UpdateWorkOrderAllocationRequest $request, WorkOrder $workOrder, WorkOrderAllocation $allocation)
    {
        return $this->successResponse(
            'Reparto actualizado correctamente.',
            new WorkOrderAllocationResource($this->allocationService->update($allocation, $request->validated())),
            200
        );
    }

    public function confirm(Request $request, WorkOrder $workOrder, WorkOrderAllocation $allocation)
    {
        return $this->successResponse(
            'Reparto confirmado correctamente.',
            new WorkOrderAllocationResource($this->allocationService->confirm($allocation, $request->user())),
            200
        );
    }

    public function destroy(WorkOrder $workOrder, WorkOrderAllocation $allocation)
    {
        $this->allocationService->delete($allocation);

        return $this->successResponse('Borrador del reparto eliminado correctamente.', null, 200);
    }
}

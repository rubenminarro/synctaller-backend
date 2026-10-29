<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Resources\WorkOrderResource;
use App\Http\Resources\ShowWorkOrderResource;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Models\WorkOrder;
use App\Models\Mechanic;
use App\Models\Budget;
use App\Services\WorkOrderService;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class WorkOrderController extends Controller implements HasMiddleware
{
    
    use ApiResponse;

    public function __construct(
        protected WorkOrderService $workOrderService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:work-orders.index', only: ['index']),
            new Middleware('permission:work-orders.store', only: ['store']),
            new Middleware('permission:work-orders.show', only: ['show']),
            new Middleware('permission:work-orders.update', only: ['update']),
            new Middleware('permission:work-orders.start', only: ['start']),
            new Middleware('permission:work-orders.pause', only: ['pause']),
            new Middleware('permission:work-orders.resume', only: ['resume']),
            new Middleware('permission:work-orders.complete', only: ['complete']),
            new Middleware('permission:work-orders.cancel', only: ['cancel']),
        ];
    }
    
    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));

        $query = WorkOrder::query()
            ->with([
                'reception',
                'reception.client',
                'reception.vehicle.brand',
                'reception.vehicle.vehicleModel',
                'budget',
                'mechanic',
                'mechanic.user',
                'creator',
            ]);

        $query->when($request->filled('search'), function ($query) use ($search) {

            $query->where(function ($q) use ($search) {

                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search)
                        ->orWhere('reception_id', (int) $search)
                        ->orWhere('budget_id', (int) $search)
                        ->orWhere('mechanic_id', (int) $search)
                        ->orWhere('created_by', (int) $search);
                }

                $q->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");

                $q->orWhereHas('reception.client', function ($clientQuery) use ($search) {

                    $clientQuery->where('document_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereRaw(
                            "CONCAT(first_name,' ',last_name) LIKE ?",
                            ["%{$search}%"]
                        );

                })->orWhereHas('reception.vehicle', function ($vehicleQuery) use ($search) {

                    $vehicleQuery->where('plate', 'like', "%{$search}%")
                        ->orWhere('chassis', 'like', "%{$search}%")
                        ->orWhere('engine_number', 'like', "%{$search}%")
                        ->orWhere('color', 'like', "%{$search}%");

                    if (is_numeric($search)) {
                        $vehicleQuery->orWhere('year', (int) $search);
                    }

                });

            });

        });

        $query->when($request->filled('reception_id'), function ($q) use ($request) {
            $q->where('reception_id', $request->reception_id);
        });

        $query->when($request->filled('budget_id'), function ($q) use ($request) {
            $q->where('budget_id', $request->budget_id);
        });

        $query->when($request->filled('mechanic_id'), function ($q) use ($request) {
            $q->where('mechanic_id', $request->mechanic_id);
        });

        $query->when($request->filled('code'), function ($q) use ($request) {
            $q->where('code', $request->code);
        });

        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        $query->when($request->filled('created_by'), function ($q) use ($request) {
            $q->where('created_by', $request->created_by);
        });

        $query->when($request->filled('date_from'), function ($q) use ($request) {
            $q->whereDate('created_at', '>=', $request->date_from);
        });

        $query->when($request->filled('date_to'), function ($q) use ($request) {
            $q->whereDate('created_at', '<=', $request->date_to);
        });

        $workOrders = $query
            ->latest()
            ->paginate($request->per_page ?? 10);

        return $this->successResponse(
            'Órdenes de trabajo obtenidas correctamente.',
            WorkOrderResource::collection($workOrders->items()),
            200,
            [
                'pagination' => [
                    'total'       => $workOrders->total(),
                    'perPage'     => $workOrders->perPage(),
                    'currentPage' => $workOrders->currentPage(),
                    'lastPage'    => $workOrders->lastPage(),
                ]
            ]
        );
    }

    public function show(WorkOrder $workOrder)
    {
        $workOrder->load([
            'reception',
            'reception.client',
            'reception.vehicle.brand',
            'reception.vehicle.vehicleModel',
            'budget',
            'mechanic',
            'mechanic.user',
            'creator',
            'items',
            'items.budgetItem',
            'items.mechanicAssignments.mechanic.user',
            'parts.part',
            'currentInvoice',
        ]);

        return $this->successResponse(
            'Orden de trabajo obtenida correctamente.',
            new ShowWorkOrderResource($workOrder),
            200
        );
    }

    public function store(StoreWorkOrderRequest $request)
    {
        $data = $request->validated();
    
        $budget = Budget::findOrFail($data['budget_id']);

        $mechanic = Mechanic::findOrFail($data['mechanic_id']);

        $workOrder = $this->workOrderService->createFromBudget(
            $budget,
            $mechanic,
            Auth::id(),
            $data['internal_exception_id'] ?? null
        );

        return $this->successResponse(
            'Orden de trabajo creada correctamente.',
            new ShowWorkOrderResource($workOrder),
            201
        );
    }

    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder)
    {
        $data = $request->validated();

        $workOrder = $this->workOrderService->update(
            $workOrder,
            $data
        );

        $workOrder->load([
            'reception',
            'reception.client',
            'reception.vehicle.brand',
            'reception.vehicle.vehicleModel',
            'budget',
            'mechanic',
            'mechanic.user',
            'creator',
            'items',
            'items.budgetItem',
            'items.mechanicAssignments.mechanic.user',
            'parts.part',
        ]);

        return $this->successResponse(
            'Orden de trabajo actualizada correctamente.',
            new ShowWorkOrderResource($workOrder),
            200
        );
    }

    public function start(WorkOrder $workOrder)
    {
        $workOrder = $this->workOrderService->start($workOrder);

        return $this->successResponse(
            'Orden de trabajo iniciada correctamente.',
            new ShowWorkOrderResource($workOrder),
            200
        );
    }

    public function pause(WorkOrder $workOrder)
    {
        $workOrder = $this->workOrderService->pause($workOrder);

        return $this->successResponse(
            'Orden de trabajo pausada correctamente.',
            new ShowWorkOrderResource($workOrder),
            200
        );
    }

    public function resume(WorkOrder $workOrder)
    {
        $workOrder = $this->workOrderService->resume($workOrder);

        return $this->successResponse(
            'Orden de trabajo reanudada correctamente.',
            new ShowWorkOrderResource($workOrder),
            200
        );
    }

    public function complete(WorkOrder $workOrder)
    {
        $workOrder = $this->workOrderService->complete($workOrder, Auth::id());

        return $this->successResponse(
            'Orden de trabajo completada correctamente.',
            new ShowWorkOrderResource($workOrder),
            200
        );
    }

    public function cancel(WorkOrder $workOrder)
    {
        $workOrder = $this->workOrderService->cancel($workOrder);

        return $this->successResponse(
            'Orden de trabajo cancelada correctamente.',
            new ShowWorkOrderResource($workOrder),
            200
        );
    }

}
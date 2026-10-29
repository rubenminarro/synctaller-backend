<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\DecideBudgetRequest;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Http\Resources\ShowBudgetResource;
use App\Models\Budget;
use App\Services\BudgetService;
use App\Services\DocumentPdfService;
use App\Traits\ApiResponse;
use App\Traits\FileResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class BudgetController extends Controller implements HasMiddleware
{
    use ApiResponse, FileResponse;

    public function __construct(
        protected BudgetService $budgetService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:budgets.index', only: ['index']),
            new Middleware('permission:budgets.store', only: ['store']),
            new Middleware('permission:budgets.show', only: ['show', 'pdf']),
            new Middleware('permission:budgets.update', only: ['update']),
            new Middleware('permission:budgets.destroy', only: ['destroy']),
            new Middleware('permission:budgets.send', only: ['send']),
            new Middleware('permission:budgets.approve', only: ['approve']),
            new Middleware('permission:budgets.reject', only: ['reject']),
            new Middleware('permission:budgets.cancel', only: ['cancel']),
            new Middleware('permission:budgets.reopen', only: ['reopen']),
        ];
    }

    public function index(Request $request)
    {
        
        $search = trim($request->input('search', ''));

        $query = Budget::query()
            ->with([
                'reception',
                'reception.client',
                'reception.vehicle.brand',
                'reception.vehicle.vehicleModel',
                'creator',
            ]);

        $query->when($request->filled('search'), function ($query) use ($search) {

    
            $query->where(function ($q) use ($search) {

                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search)
                        ->orWhere('reception_id', (int) $search)
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

                })
                ->orWhereHas('reception.vehicle', function ($vehicleQuery) use ($search) {

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

        $query->when($request->filled('code'), function ($q) use ($request) {
            $q->where('code', $request->code);
        });

        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        $query->when($request->filled('created_by'), function ($q) use ($request) {
            $q->where('created_by', $request->created_by);
        });

        $query->when($request->filled('notes'), function ($q) use ($request) {
            $q->where('notes', $request->notes);
        });

        $query->when($request->filled('date_from'), function ($q) use ($request) {
            $q->whereDate('created_at', '>=', $request->date_from);
        });

        $query->when($request->filled('date_to'), function ($q) use ($request) {
            $q->whereDate('created_at', '<=', $request->date_to);
        });

        $budgets = $query
            ->latest()
            ->paginate($request->per_page ?? 10);

        return $this->successResponse(
            'Presupuestos obtenidos correctamente.',
            BudgetResource::collection($budgets->items()),
            200,
            [
                'pagination' => [
                    'total'       => $budgets->total(),
                    'perPage'     => $budgets->perPage(),
                    'currentPage' => $budgets->currentPage(),
                    'lastPage'    => $budgets->lastPage(),
                ]
            ]
        );
        
    }

    public function store(StoreBudgetRequest $request)
    {
        $data = $request->validated();

        $budget = $this->budgetService->create(
            $data,
            Auth::id()
        );

        $this->loadShowRelations($budget);

        return $this->successResponse(
            'Presupuesto creado exitosamente.',
            new ShowBudgetResource($budget),
            201
        );
    }
    
    public function show(Budget $budget)
    {
        $this->loadShowRelations($budget);

        return $this->successResponse(
            'Presupuesto recuperado exitosamente.',
            new ShowBudgetResource($budget)
        );
    }

    public function pdf(Budget $budget, DocumentPdfService $documentPdfService)
    {
        return $this->fileResponse($documentPdfService->budget($budget), 'application/pdf');
    }
    
    public function update(UpdateBudgetRequest $request, Budget $budget)
    {
        $budget = $this->budgetService->update(
            $budget,
            $request->validated()
        );

        $this->loadShowRelations($budget);

        return $this->successResponse(
            'Presupuesto actualizado exitosamente.',
            new ShowBudgetResource($budget)
        );
    }

    public function destroy(Budget $budget)
    {
        $this->budgetService->delete($budget);

        return $this->successResponse(
            'Presupuesto eliminado exitosamente.',
            null
        );
    }

    public function send(Budget $budget)
    {
        $budget = $this->budgetService->send($budget);

        $this->loadShowRelations($budget);

        return $this->successResponse(
            'Presupuesto enviado exitosamente.',
            new ShowBudgetResource($budget)
        );
    }

    public function approve(DecideBudgetRequest $request, Budget $budget)
    {
        $budget = $this->budgetService->approve($budget, $request->user(), $request->validated());

        $this->loadShowRelations($budget);

        return $this->successResponse(
            'Presupuesto aprobado exitosamente.',
            new ShowBudgetResource($budget)
        );
    }

    public function reject(DecideBudgetRequest $request, Budget $budget)
    {
        $budget = $this->budgetService->reject($budget, $request->user(), $request->validated());

        $this->loadShowRelations($budget);

        return $this->successResponse(
            'Presupuesto rechazado exitosamente.',
            new ShowBudgetResource($budget)
        );
    }

    public function cancel(Budget $budget)
    {
        $budget = $this->budgetService->cancel($budget);

        $this->loadShowRelations($budget);

        return $this->successResponse(
            'Presupuesto cancelado exitosamente.',
            new ShowBudgetResource($budget)
        );
    }

    public function reopen(Budget $budget)
    {
        $budget = $this->budgetService->reopen($budget);

        $this->loadShowRelations($budget);

        return $this->successResponse(
            'Presupuesto reabierto exitosamente.',
            new ShowBudgetResource($budget)
        );
    }

    private function loadShowRelations(Budget $budget): void
    {
        $budget->load([
            'items.part',
            'items.service',
            'reception.client',
            'reception.vehicle.brand',
            'reception.vehicle.vehicleModel',
            'creator',
            'clientAcceptances.decidedBy',
        ]);
    }
    
}

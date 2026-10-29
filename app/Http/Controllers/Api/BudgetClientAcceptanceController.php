<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReacceptBudgetRequest;
use App\Http\Resources\BudgetClientAcceptanceResource;
use App\Models\Budget;
use App\Services\BudgetService;
use App\Traits\ApiResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Respuestas del cliente a un presupuesto (budgets/{budget}/client-acceptances):
 * el historial y la re-aceptación del dueño en un siniestro (M7). La aprobación y
 * el rechazo siguen en budgets/{budget}/approve|reject.
 */
class BudgetClientAcceptanceController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        protected BudgetService $budgetService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:budgets.show', only: ['index']),
            new Middleware('permission:budgets.approve', only: ['store']),
        ];
    }

    public function index(Budget $budget)
    {
        return $this->successResponse(
            'Respuestas del cliente obtenidas correctamente.',
            BudgetClientAcceptanceResource::collection(
                $budget->clientAcceptances()->with(['decidedBy', 'insurerAuthorization'])->get()
            ),
            200
        );
    }

    public function store(ReacceptBudgetRequest $request, Budget $budget)
    {
        return $this->successResponse(
            'Re-aceptación del cliente registrada correctamente.',
            new BudgetClientAcceptanceResource($this->budgetService->reaccept($budget, $request->user(), $request->validated())),
            201
        );
    }
}

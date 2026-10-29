<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBudgetItemRequest;
use App\Http\Requests\UpdateBudgetItemRequest;
use App\Http\Resources\BudgetItemResource;
use App\Models\Budget;
use App\Models\BudgetItem;
use App\Services\BudgetService;
use App\Traits\ApiResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class BudgetItemController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        protected BudgetService $budgetService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:budget-items.index', only: ['index']),
            new Middleware('permission:budget-items.store', only: ['store']),
            new Middleware('permission:budget-items.show', only: ['show']),
            new Middleware('permission:budget-items.update', only: ['update']),
            new Middleware('permission:budget-items.destroy', only: ['destroy']),
        ];
    }

    public function index(Budget $budget)
    {
        $items = $budget->items()->with(['part', 'service'])->latest()->get();

        return $this->successResponse(
            'Items del presupuesto obtenidos exitosamente.',
            BudgetItemResource::collection($items)
        );
    }

    public function store(StoreBudgetItemRequest $request, Budget $budget) 
    {

        $this->budgetService->ensureEditable($budget);

        $data = $this->budgetService->prepareCatalogItem($request->validated());

        $this->budgetService->ensureResponsibilityAllowed($budget, $data);

        $data['total'] = $this->budgetService->itemTotal($data['quantity'], $data['unit_price']);

        // Si el nuevo total del presupuesto queda fuera de rango, el ítem no se guarda.
        $item = DB::transaction(function () use ($budget, $data) {
            $item = $budget->items()->create($data);

            $this->budgetService->recalculateBudget($budget);

            return $item;
        });

        return $this->successResponse(
            'El item del presupuesto creado exitosamente.',
            new BudgetItemResource($item->load(['part', 'service'])),
            201
        );
    }

    public function show(Budget $budget, BudgetItem $item)
    {
        
        return $this->successResponse(
            'El item del presupuesto recuperado exitosamente.',
            new BudgetItemResource($item->load(['part', 'service']))
        );
    }

    public function update(UpdateBudgetItemRequest $request, Budget $budget, BudgetItem $item) 
    {
        
        $this->budgetService->ensureEditable($budget);

        $data = $this->budgetService->prepareCatalogItem($request->validated(), $item);

        $this->budgetService->ensureResponsibilityAllowed($budget, $data);

        $quantity = $data['quantity'] ?? $item->quantity;
        $unitPrice = $data['unit_price'] ?? $item->unit_price;

        $data['total'] = $this->budgetService->itemTotal($quantity, $unitPrice);

        DB::transaction(function () use ($budget, $item, $data) {
            $item->update($data);

            $this->budgetService->recalculateBudget($budget);
        });

        return $this->successResponse(
            'El item del presupuesto actualizado exitosamente.',
            new BudgetItemResource($item->refresh()->load(['part', 'service']))
        );
    }

    public function destroy(Budget $budget, BudgetItem $item) 
    {
        $this->budgetService->ensureEditable($budget);

        $item->delete();

        $this->budgetService->recalculateBudget($budget);

        return $this->successResponse(
            'El item del presupuesto eliminado exitosamente.',
            null
        );
    }

}

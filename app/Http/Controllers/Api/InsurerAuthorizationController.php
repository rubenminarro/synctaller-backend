<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInsurerAuthorizationRequest;
use App\Http\Resources\InsurerAuthorizationResource;
use App\Models\Budget;
use App\Models\InsurerAuthorization;
use App\Services\InsurerAuthorizationService;
use App\Traits\ApiResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Autorizaciones de la aseguradora sobre un presupuesto (M6), en
 * budgets/{budget}/insurer-authorizations (scopeBindings). Solo se registran:
 * no se editan ni se eliminan (una nueva versión sustituye a la vigente).
 */
class InsurerAuthorizationController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        protected InsurerAuthorizationService $authorizationService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:insurer-authorizations.index', only: ['index']),
            new Middleware('permission:insurer-authorizations.store', only: ['store']),
            new Middleware('permission:insurer-authorizations.show', only: ['show']),
        ];
    }

    public function index(Budget $budget)
    {
        return $this->successResponse(
            'Autorizaciones de la aseguradora obtenidas correctamente.',
            InsurerAuthorizationResource::collection(
                $budget->insurerAuthorizations()->with(['items', 'documents', 'registrar'])->get()
            ),
            200
        );
    }

    public function store(StoreInsurerAuthorizationRequest $request, Budget $budget)
    {
        return $this->successResponse(
            'Autorización de la aseguradora registrada correctamente.',
            new InsurerAuthorizationResource($this->authorizationService->register($budget, $request->validated(), $request->user())),
            201
        );
    }

    public function show(Budget $budget, InsurerAuthorization $insurerAuthorization)
    {
        return $this->successResponse(
            'Autorización de la aseguradora obtenida correctamente.',
            new InsurerAuthorizationResource($this->authorizationService->load($insurerAuthorization)),
            200
        );
    }
}

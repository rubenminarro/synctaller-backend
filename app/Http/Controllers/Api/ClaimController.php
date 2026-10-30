<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelReceptionRequest;
use App\Http\Requests\StoreClaimRequest;
use App\Http\Requests\UpdateClaimRequest;
use App\Http\Resources\ClaimResource;
use App\Http\Resources\ShowClaimResource;
use App\Models\Claim;
use App\Models\Reception;
use App\Services\ClaimService;
use App\Services\ClaimSummaryService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ClaimController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        protected ClaimService $claimService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:claims.index', only: ['index']),
            new Middleware('permission:claims.store', only: ['store']),
            new Middleware('permission:claims.show', only: ['show', 'forReception', 'summary']),
            new Middleware('permission:claims.update', only: ['update']),
            new Middleware('permission:claims.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));

        $claims = Claim::query()
            ->with(['insurer.client', 'reception.vehicle'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('claim_number', 'like', "%{$search}%")
                        ->orWhere('policy_number', 'like', "%{$search}%")
                        ->orWhere('insured_name', 'like', "%{$search}%")
                        ->orWhereHas('reception.vehicle', fn ($v) => $v->where('plate', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('insurer_id'), fn ($q) => $q->where('insurer_id', $request->insurer_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('id')
            ->paginate($request->per_page ?? 10);

        return $this->successResponse(
            'Siniestros obtenidos correctamente.',
            ClaimResource::collection($claims->items()),
            200,
            [
                'pagination' => [
                    'total'       => $claims->total(),
                    'perPage'     => $claims->perPage(),
                    'currentPage' => $claims->currentPage(),
                    'lastPage'    => $claims->lastPage(),
                ]
            ]
        );
    }

    public function store(StoreClaimRequest $request)
    {
        return $this->successResponse(
            'Siniestro registrado correctamente.',
            new ShowClaimResource($this->claimService->create($request->validated(), $request->user())),
            201
        );
    }

    public function show(Claim $claim)
    {
        return $this->successResponse(
            'Siniestro obtenido correctamente.',
            new ShowClaimResource($this->claimService->load($claim)),
            200
        );
    }

    /**
     * Siniestro vigente de la recepción (404 si no tiene).
     */
    public function forReception(Reception $reception)
    {
        $claim = $reception->currentClaim()->firstOrFail();

        return $this->successResponse(
            'Siniestro de la recepción obtenido correctamente.',
            new ShowClaimResource($this->claimService->load($claim)),
            200
        );
    }

    /**
     * Resumen económico (M11). Presupuestado, autorizado y aceptado con claims.show; el reparto solo con
     * work-order-allocations.show y lo facturado y cobrado solo con invoices.show.
     */
    public function summary(Request $request, Claim $claim, ClaimSummaryService $claimSummaryService)
    {
        $blocks = collect([
            ClaimSummaryService::BUDGETS => 'claims.show',
            ClaimSummaryService::ALLOCATIONS => 'work-order-allocations.show',
            ClaimSummaryService::BILLING => 'invoices.show',
        ])->filter(fn (string $permission) => $request->user()->can($permission))->keys()->all();

        return $this->successResponse(
            'Resumen del siniestro obtenido correctamente.',
            $claimSummaryService->summary($claim, $blocks),
            200
        );
    }

    public function update(UpdateClaimRequest $request, Claim $claim)
    {
        return $this->successResponse(
            'Siniestro actualizado correctamente.',
            new ShowClaimResource($this->claimService->update($claim, $request->validated())),
            200
        );
    }

    public function cancel(CancelReceptionRequest $request, Claim $claim)
    {
        return $this->successResponse(
            'Siniestro cancelado correctamente.',
            new ShowClaimResource($this->claimService->cancel($claim, $request->validated('reason'), $request->user())),
            200
        );
    }
}

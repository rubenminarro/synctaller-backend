<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Reception;
use App\Models\ReceptionCheckList;
use App\Http\Resources\ReceptionResource;
use App\Http\Requests\StoreReceptionRequest;
use App\Http\Requests\UpdateReceptionRequest;
use App\Http\Resources\ShowReceptionResource;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\ReceptionChecklistService;
use App\Services\ReceptionStatusService;
use App\Http\Requests\CancelReceptionRequest;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ReceptionController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:receptions.index', only: ['index']),
            new Middleware('permission:receptions.store', only: ['store']),
            new Middleware('permission:receptions.show', only: ['show']),
            new Middleware('permission:receptions.update', only: ['update']),
            new Middleware('permission:receptions.destroy', only: ['destroy']),
            new Middleware('permission:receptions.cancel', only: ['cancel']),
        ];
    }

    public function index(Request $request)
    {
        $search = $request->input('search');

        $query = Reception::query()
            ->with(['client', 'vehicle.brand', 'vehicle.vehicleModel', 'createdBy']);

        $query->when($request->filled('plate'), function ($q) use ($request) {
            $q->whereHas('vehicle', function ($v) use ($request) {
                $v->where('plate', 'like', "%{$request->plate}%");
            });
        });

        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        $query->when($request->filled('reception_start_date'), function ($q) use ($request) {
            $q->whereDate('reception_date', '>=', $request->reception_start_date);
        });

        $query->when($request->filled('reception_end_date'), function ($q) use ($request) {
            $q->whereDate('reception_date', '<=', $request->reception_end_date);
        });

        $query->when($request->filled('estimated_delivery_start_date'), function ($q) use ($request) {
            $q->whereDate('estimated_delivery_date', '>=', $request->estimated_delivery_start_date);
        });

        $query->when($request->filled('estimated_delivery_end_date'), function ($q) use ($request) {
            $q->whereDate('estimated_delivery_date', '<=', $request->estimated_delivery_end_date);
        });

        $query->when($request->filled('search'), function ($q) use ($search) {
            $q->where(function ($subQuery) use ($search) {
                $subQuery->where('id', 'like', "%{$search}%")
                    ->orWhere('problem_description', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($c) use ($search) {
                        $c->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('business_name', 'like', "%{$search}%")
                            ->orWhere('document_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('vehicle', function ($v) use ($search) {
                        $v->where('chassis', 'like', "%{$search}%")
                            ->orWhere('plate', 'like', "%{$search}%")
                            ->orWhere('year', 'like', "%{$search}%")
                            ->orWhere('color', 'like', "%{$search}%")
                            ->orWhere('engine_number', 'like', "%{$search}%")
                            ->orWhere('fuel_type', 'like', "%{$search}%");
                    })
                    ->orWhereHas('createdBy', function ($cb) use ($search) {
                        $cb->where('name', 'like', "%{$search}%");
                    });
            });
        });

        $receptions = $query->latest()->paginate($request->per_page ?? 10);

        return $this->successResponse(
            'Recepciones obtenidas correctamente.',
            ReceptionResource::collection($receptions->items()),
            200,
            [
                'pagination' => [
                    'total'       => $receptions->total(),
                    'perPage'     => $receptions->perPage(),
                    'currentPage' => $receptions->currentPage(),
                    'lastPage'    => $receptions->lastPage(),
                ]
            ]
        );
    }

    public function store(
        StoreReceptionRequest $request,
        ReceptionChecklistService $checklistService
    ) {
        $data = $request->validated();

        $data['created_by'] = Auth::id();

        $reception = DB::transaction(function () use (
            $data,
            $checklistService
        ) {
            $reception = Reception::create($data);

            $reception->serviceCategories()->sync(
                $data['service_category_ids']
            );

            $checkList = ReceptionCheckList::create([
                'reception_id' => $reception->id,
            ]);

            $checklistService->generateChecklistItems(
                $checkList,
                $data['service_category_ids']
            );

            return $reception;
        });

        return $this->successResponse(
            'Recepción creada correctamente.',
            new ShowReceptionResource(
                $reception->fresh()->load([
                    'client',
                    'vehicle.brand',
                    'vehicle.vehicleModel',
                    'createdBy',
                    'serviceCategories',
                    'checkList',
                ])
            ),
            201
        );
    }

    public function show(Reception $reception)
    {
        return $this->successResponse(
            'Recepción encontrada.',
            new ShowReceptionResource(
                $reception->load([
                    'client',
                    'vehicle.brand',
                    'vehicle.vehicleModel',
                    'createdBy',
                    'serviceCategories',
                    'checkList',
                ])
            ),
            200
        );
    }

    public function update(
        UpdateReceptionRequest $request,
        Reception $reception,
        ReceptionChecklistService $checklistService,
        ReceptionStatusService $receptionStatusService
    ) {
        // Entregada o cancelada: no se modifica.
        $receptionStatusService->ensureEditable($reception);

        $data = $request->validated();

        $hasServiceCategories = array_key_exists('service_category_ids', $data);

        if (
            $hasServiceCategories
            && $reception->status->isProcessed()
        ) {
            return $this->errorResponse(
                'No se pueden modificar las categorías de servicio una vez procesada la recepción.',
                422,
                'RECEPTION_CATEGORIES_NOT_EDITABLE'
            );
        }

        DB::transaction(function () use (
            $reception,
            $data,
            $hasServiceCategories,
            $checklistService
        ) {
            $receptionData = $data;

            unset($receptionData['service_category_ids']);

            $reception->update($receptionData);

            if ($hasServiceCategories) {

                $reception->serviceCategories()->sync(
                    $data['service_category_ids']
                );

                $checkList = $reception->checkList;

                if ($checkList) {
                    $checkList->items()->delete();

                    $checklistService->generateChecklistItems(
                        $checkList,
                        $data['service_category_ids']
                    );
                }
            }
        });

        return $this->successResponse(
            'Recepción actualizada correctamente.',
            new ShowReceptionResource(
                $reception->fresh()->load([
                    'client',
                    'vehicle.brand',
                    'vehicle.vehicleModel',
                    'createdBy',
                    'serviceCategories',
                    'checkList',
                ])
            )
        );
    }

    /**
     * Cancelación manual (por ejemplo, el cliente desiste): solo sin OT vigentes.
     */
    public function cancel(
        CancelReceptionRequest $request,
        Reception $reception,
        ReceptionStatusService $receptionStatusService
    ) {
        $reception = $receptionStatusService->cancel(
            $reception,
            $request->validated('reason'),
            $request->user()
        );

        return $this->successResponse(
            'Recepción cancelada correctamente.',
            new ShowReceptionResource(
                $reception->load(['client', 'vehicle.brand', 'vehicle.vehicleModel', 'createdBy', 'serviceCategories', 'checkList'])
            )
        );
    }

    public function destroy(Reception $reception)
    {
        // Procesada = ya avanzó en el flujo (todo salvo pending).
        if ($reception->status->isProcessed()) {
            return $this->errorResponse(
                'No se puede eliminar una recepción procesada.',
                422,
                'RECEPTION_NOT_DELETABLE'
            );
        }

        $reception->delete();

        return $this->successResponse(
            'Recepción eliminada correctamente.',
            null,
            200
        );
    }
}
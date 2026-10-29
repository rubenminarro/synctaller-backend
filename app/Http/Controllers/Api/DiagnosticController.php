<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Resources\DiagnosticResource;
use App\Http\Requests\StoreDiagnosticRequest;
use App\Http\Requests\UpdateDiagnosticRequest;
use App\Http\Resources\ShowDiagnosticResource;
use App\Models\Diagnostic;
use App\Traits\ApiResponse;
use App\Enums\DiagnosticStatus;
use Illuminate\Support\Facades\DB;
use App\Services\DiagnosticService;
use App\Services\ReceptionStatusService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class DiagnosticController extends Controller implements HasMiddleware
{
    
    use ApiResponse;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:diagnostics.index', only: ['index']),
            new Middleware('permission:diagnostics.store', only: ['store']),
            new Middleware('permission:diagnostics.show', only: ['show']),
            new Middleware('permission:diagnostics.update', only: ['update']),
            new Middleware('permission:diagnostics.destroy', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));

        $query = Diagnostic::query()
            ->with([
                'reception.client',
                'reception.vehicle.brand',
                'reception.vehicle.vehicleModel',
                'mechanic.user',
            ]);

        $query->when($request->filled('search'), function ($query) use ($search) {

            $query->where(function ($q) use ($search) {

                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search)
                    ->orWhere('reception_id', (int) $search);
                }

                $q->orWhere('customer_complaint', 'like', "%{$search}%")
                ->orWhere('diagnosis', 'like', "%{$search}%")
                ->orWhere('recommendation', 'like', "%{$search}%");

                $q->orWhereHas('reception.client', function ($client) use ($search) {

                    $client->where('document_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereRaw(
                            "CONCAT(first_name,' ',last_name) LIKE ?",
                            ["%{$search}%"]
                        );

                });

                $q->orWhereHas('reception.vehicle', function ($vehicleQuery) use ($search) {

                    $vehicleQuery->where('plate', 'like', "%{$search}%")
                        ->orWhere('chassis', 'like', "%{$search}%")
                        ->orWhere('engine_number', 'like', "%{$search}%")
                        ->orWhere('year', 'like', "%{$search}%");

                });

                $q->orWhereHas('reception.vehicle.brand', function ($brandQuery) use ($search) {

                    $brandQuery->where('name', 'like', "%{$search}%");

                });

                $q->orWhereHas('reception.vehicle.vehicleModel', function ($modelQuery) use ($search) {

                    $modelQuery->where('name', 'like', "%{$search}%");

                });

                $q->orWhereHas('mechanic.user', function ($userQuery) use ($search) {

                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhereRaw(
                            "CONCAT(first_name,' ',last_name) LIKE ?",
                            ["%{$search}%"]
                        );

                });

            });

        });

        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        $query->when($request->filled('priority'), function ($q) use ($request) {
            $q->where('priority', $request->priority);
        });

        $query->when($request->filled('mechanic_id'), function ($q) use ($request) {
            $q->where('mechanic_id', $request->mechanic_id);
        });

        $query->when($request->filled('reception_id'), function ($q) use ($request) {
            $q->where('reception_id', $request->reception_id);
        });

        $query->when($request->filled('client_id'), function ($q) use ($request) {
            $q->whereHas('reception', function ($reception) use ($request) {
                $reception->where('client_id', $request->client_id);
            });
        });

        $query->when($request->filled('vehicle_id'), function ($q) use ($request) {
            $q->whereHas('reception', function ($reception) use ($request) {
                $reception->where('vehicle_id', $request->vehicle_id);
            });
        });

        $query->when($request->filled('plate'), function ($q) use ($request) {
            $q->whereHas('reception.vehicle', function ($vehicle) use ($request) {
                $vehicle->where('plate', 'like', "%{$request->plate}%");
            });
        });

        $query->when($request->filled('requires_parts'), function ($q) use ($request) {
            $q->where('requires_parts', $request->boolean('requires_parts'));
        });

        $query->when($request->filled('requires_repair'), function ($q) use ($request) {
            $q->where('requires_repair', $request->boolean('requires_repair'));
        });

        $query->when($request->filled('diagnosed_from'), function ($q) use ($request) {
            $q->whereDate('diagnosed_at', '>=', $request->diagnosed_from);
        });

        $query->when($request->filled('diagnosed_to'), function ($q) use ($request) {
            $q->whereDate('diagnosed_at', '<=', $request->diagnosed_to);
        });

        $query->when($request->filled('reception_start_date'), function ($q) use ($request) {
            $q->whereHas('reception', function ($reception) use ($request) {
                $reception->whereDate(
                    'reception_date',
                    '>=',
                    $request->reception_start_date
                );
            });
        });

        $query->when($request->filled('reception_end_date'), function ($q) use ($request) {
            $q->whereHas('reception', function ($reception) use ($request) {
                $reception->whereDate(
                    'reception_date',
                    '<=',
                    $request->reception_end_date
                );
            });
        });

        $query->when($request->filled('estimated_delivery_start_date'), function ($q) use ($request) {
            $q->whereHas('reception', function ($reception) use ($request) {
                $reception->whereDate(
                    'estimated_delivery_date',
                    '>=',
                    $request->estimated_delivery_start_date
                );
            });
        });

        $query->when($request->filled('estimated_delivery_end_date'), function ($q) use ($request) {
            $q->whereHas('reception', function ($reception) use ($request) {
                $reception->whereDate(
                    'estimated_delivery_date',
                    '<=',
                    $request->estimated_delivery_end_date
                );
            });
        });

        // Por fecha del diagnóstico (la que se muestra), no de carga del registro.
        $diagnostics = $query
            ->orderByDesc('diagnosed_at')
            ->orderByDesc('id')
            ->paginate($request->per_page ?? 10);

        return $this->successResponse(
            'Diagnósticos obtenidos correctamente.',
            DiagnosticResource::collection($diagnostics->items()),
            200,
            [
                'pagination' => [
                    'total'       => $diagnostics->total(),
                    'perPage'     => $diagnostics->perPage(),
                    'currentPage' => $diagnostics->currentPage(),
                    'lastPage'    => $diagnostics->lastPage(),
                ]
            ]
        );
    }

    public function store(StoreDiagnosticRequest $request, ReceptionStatusService $receptionStatusService)
    {
        $data = $request->validated();

        // No se diagnostica sobre recepciones entregadas o canceladas.
        $receptionStatusService->ensureOpen((int) $data['reception_id']);

        $data['status'] = DiagnosticStatus::PENDING;
        $data['diagnosed_at'] = now();

        DB::beginTransaction();

        try {
            $diagnostic = Diagnostic::create($data);

            DB::commit();

            // refresh: los defaults de la base (p. ej. priority = medium) no quedan en el modelo recién creado.
            $diagnostic->refresh()->load([
                'reception',
                'mechanic'
            ]);

            return $this->successResponse(
                'Diagnóstico creado correctamente.',
                new ShowDiagnosticResource($diagnostic),
                201
            );

        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    public function show(Diagnostic $diagnostic)
    {
        $diagnostic->load([
            'reception.client',
            'reception.vehicle.brand',
            'reception.vehicle.vehicleModel',
            'reception.photos',
            'mechanic.user',
            'items.photos',
            'items.template',
        ]);

        return $this->successResponse(
            'Diagnóstico obtenido correctamente.',
            new ShowDiagnosticResource($diagnostic)
        );
    }

    public function update(UpdateDiagnosticRequest $request, Diagnostic $diagnostic, DiagnosticService $diagnosticService)
    {
        $diagnosticService->ensureEditable($diagnostic);

        $diagnostic->update(
            $request->validated()
        );

        $diagnostic->load([
            'reception',
            'mechanic'
        ]);

        return $this->successResponse(
            'Diagnóstico actualizado correctamente.',
            new ShowDiagnosticResource($diagnostic)
        );
    }

     public function destroy(Diagnostic $diagnostic, DiagnosticService $diagnosticService)
    {
        $diagnosticService->ensureEditable($diagnostic);

        $diagnostic->delete();

        return $this->successResponse(
            'Diagnóstico eliminado correctamente.',
            null
        );
    }

}

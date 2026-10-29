<?php

namespace App\Http\Controllers\Api;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Http\Resources\ShowVehicleResource;
use App\Services\VehicleService;
use App\Traits\ApiResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class VehicleController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        protected VehicleService $vehicleService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:vehicles.index', only: ['index']),
            new Middleware('permission:vehicles.store', only: ['store']),
            new Middleware('permission:vehicles.show', only: ['show']),
            new Middleware('permission:vehicles.update', only: ['update']),
            new Middleware('permission:vehicles.destroy', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        
        $vehicles = Vehicle::query()
        ->with([
            'client',
            'brand',
            'vehicleModel',
        ])
        ->when($request->filled('search'), function ($query) use ($request) {
            
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('chassis', 'like', "%{$search}%")
                ->orWhere('plate', 'like', "%{$search}%")
                ->orWhere('year', 'like', "%{$search}%")
                ->orWhere('color', 'like', "%{$search}%")
                ->orWhere('engine_number', 'like', "%{$search}%")
                ->orWhere('fuel_type', 'like', "%{$search}%")
                ->orWhereHas('client', function ($q) use ($search) {
                    $q->where('document_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
                })
                ->orWhereHas('brand', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%");
                })
                ->orWhereHas('vehicleModel', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%");
                });
            });
        })
        ->when($request->filled('chassis'), function ($query) use ($request) {
            $query->where('chassis', 'like', "%{$request->chassis}%");
        })
        ->when($request->filled('plate'), function ($query) use ($request) {
            $query->where('plate', 'like', "%{$request->plate}%");
        })
        ->when($request->filled('year'), function ($query) use ($request) {
            $query->where('year', 'like', "%{$request->year}%");
        })
        ->when($request->filled('color'), function ($query) use ($request) {
            $query->where('color', 'like', "%{$request->color}%");
        })
        ->when($request->filled('engine_number'), function ($query) use ($request) {
            $query->where('engine_number', 'like', "%{$request->engine_number}%");
        })
        ->when($request->filled('fuel_type'), function ($query) use ($request) {
            $query->where('fuel_type', 'like', "%{$request->fuel_type}%");
        })
        ->when($request->filled('client_document_number'), function ($query) use ($request) {
            $query->whereHas('client', function ($q) use ($request) {
                $q->where('document_number', 'like', "%{$request->client_document_number}%");
            });
        })
        ->when($request->filled('client_first_name'), function ($query) use ($request) {
            $query->whereHas('client', function ($q) use ($request) {
                // También encuentra empresas por razón social.
                $q->where('first_name', 'like', "%{$request->client_first_name}%")
                    ->orWhere('business_name', 'like', "%{$request->client_first_name}%");
            });
        })
        ->when($request->filled('client_last_name'), function ($query) use ($request) {
            $query->whereHas('client', function ($q) use ($request) {
                $q->where('last_name', 'like', "%{$request->client_last_name}%");
            });
        })
        ->when($request->filled('client_phone'), function ($query) use ($request) {
            $query->whereHas('client', function ($q) use ($request) {
                $q->where('phone', 'like', "%{$request->client_phone}%");
            });
        })
        ->when($request->filled('client_email'), function ($query) use ($request) {
            $query->whereHas('client', function ($q) use ($request) {
                $q->where('email', 'like', "%{$request->client_email}%");
            });
        })
        ->when($request->filled('brand_name'), function ($query) use ($request) {
            $query->whereHas('brand', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->brand_name}%");
            });
        })
        ->when($request->filled('vehicle_model_name'), function ($query) use ($request) {
            $query->whereHas('vehicleModel', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->vehicle_model_name}%");
            });
        })
        ->latest()
        ->paginate(
            $request->per_page ?? 10
        );

        return $this->successResponse(
            'Vehículos obtenidos correctamente.',
            VehicleResource::collection($vehicles->items()),
            200,
            [
                'pagination' => [
                    'total'       => $vehicles->total(),
                    'perPage'     => $vehicles->perPage(),
                    'currentPage' => $vehicles->currentPage(),
                    'lastPage'    => $vehicles->lastPage(),
                ]
            ]
        );
    }

    public function store(StoreVehicleRequest $request)
    {
        $vehicle = $this->vehicleService->create($request->validated());

        return $this->successResponse(
            'Vehículo creado correctamente.',
            new ShowVehicleResource($vehicle),
            201
        );
    }

    public function show(Vehicle $vehicle)
    {
        $vehicle->load([
            'client',
            'brand',
            'vehicleModel',
        ]);

                return $this->successResponse(
            'Vehículo encontrado.',
            new ShowVehicleResource($vehicle),
            200
        );
    }
    
    public function update(UpdateVehicleRequest $request, Vehicle $vehicle)
    {
        $vehicle->update($request->validated());

        $vehicle->load([
            'client',
            'brand',
            'vehicleModel',
        ]);

        return $this->successResponse(
            'Vehículo actualizado correctamente.',
            new VehicleResource($vehicle),
            200
        );
    }

    public function destroy(Vehicle $vehicle)
    {
        // Con recepciones, su chapa debe seguir intacta en recepciones, facturas, entregas e historial.
        if ($vehicle->receptions()->withTrashed()->exists()) {
            return $this->errorResponse(
                'El vehículo ya tiene recepciones registradas y no puede eliminarse. Si cambió de dueño, edite su cliente.',
                409,
                'VEHICLE_IN_USE'
            );
        }

        $suffix = '//deleted_' . now()->timestamp;

        if ($vehicle->plate) {
            $vehicle->plate = $vehicle->plate . $suffix;
        }

        if ($vehicle->chassis) {
            $vehicle->chassis = $vehicle->chassis . $suffix;
        }

        $vehicle->save();
    
        $vehicle->delete();

        return $this->successResponse(
            'Vehículo eliminado correctamente.',
            null,
            200
        );
    }
}
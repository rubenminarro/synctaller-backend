<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleHistoryRequest;
use App\Http\Resources\VehicleHistoryPartResource;
use App\Http\Resources\VehicleHistoryReceptionResource;
use App\Models\Vehicle;
use App\Services\VehicleHistoryService;
use App\Traits\ApiResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Historial del vehículo (solo lectura).
 */
class VehicleHistoryController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        protected VehicleHistoryService $historyService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:vehicle-history.view'),
        ];
    }

    public function show(VehicleHistoryRequest $request, Vehicle $vehicle)
    {
        $vehicle->loadMissing(['client', 'brand', 'vehicleModel']);

        $timeline = $this->historyService->timeline($vehicle, (int) ($request->per_page ?? 10));

        return $this->successResponse(
            'Historial del vehículo obtenido correctamente.',
            [
                'vehicle' => [
                    'id' => $vehicle->id,
                    'plate' => $vehicle->plate,
                    'chassis' => $vehicle->chassis,
                    'brand' => $vehicle->brand?->name,
                    'model' => $vehicle->vehicleModel?->name,
                    'year' => $vehicle->year,
                    'mileage' => $vehicle->mileage,
                    'client' => $vehicle->client ? [
                        'id' => $vehicle->client->id,
                        'full_name' => $vehicle->client->display_name,
                    ] : null,
                ],
                'summary' => $this->historyService->summary(
                    $vehicle,
                    (bool) $request->user()->can('work-orders.view-economics')
                ),
                'timeline' => VehicleHistoryReceptionResource::collection($timeline->items()),
            ],
            200,
            [
                'pagination' => [
                    'total'       => $timeline->total(),
                    'perPage'     => $timeline->perPage(),
                    'currentPage' => $timeline->currentPage(),
                    'lastPage'    => $timeline->lastPage(),
                ]
            ]
        );
    }

    public function parts(VehicleHistoryRequest $request, Vehicle $vehicle)
    {
        $parts = $this->historyService->parts($vehicle, (int) ($request->per_page ?? 20));

        return $this->successResponse(
            'Repuestos usados en el vehículo obtenidos correctamente.',
            VehicleHistoryPartResource::collection($parts->items()),
            200,
            [
                'pagination' => [
                    'total'       => $parts->total(),
                    'perPage'     => $parts->perPage(),
                    'currentPage' => $parts->currentPage(),
                    'lastPage'    => $parts->lastPage(),
                ]
            ]
        );
    }

    public function mileage(Vehicle $vehicle)
    {
        return $this->successResponse(
            'Kilometraje del vehículo obtenido correctamente.',
            $this->historyService->mileage($vehicle),
            200
        );
    }
}

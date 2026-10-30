<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\FuelLevel;
use App\Enums\VehicleColor;

class ShowReceptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        $fuelEnum = $this->fuel_level instanceof FuelLevel
            ? $this->fuel_level
            : FuelLevel::tryFrom($this->fuel_level);

        // Valores crudos (fechas Y-m-d, kilometraje entero): el frontend los formatea y los usa en los formularios.
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'vehicle_id' => $this->vehicle_id,
            'reception_date' => $this->reception_date?->toDateString(),
            'estimated_delivery_date' => $this->estimated_delivery_date?->toDateString(),
            'problem_description' => $this->problem_description,
            'observations' => $this->observations,
            'mileage' => $this->mileage,
            'fuel' => [
                'value' => $fuelEnum?->value,
                'label' => $fuelEnum?->label()
            ],
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->label(),
            ],
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $this->cancelled_by,
            'cancellation_reason' => $this->cancellation_reason,
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => optional($this->createdBy)->id,
                    'name' => optional($this->createdBy)->name,
                ];
            }),
            // Siniestro vigente: solo con claims.show (el mecánico ve la recepción, pero no pólizas ni datos del asegurado).
            'claim' => $this->when($request->user()?->can('claims.show') ?? false, fn () => $this->currentClaim ? [
                'id' => $this->currentClaim->id,
                'code' => $this->currentClaim->code,
                'claim_number' => $this->currentClaim->claim_number,
                'status' => [
                    'value' => $this->currentClaim->status->value,
                    'label' => $this->currentClaim->status->label(),
                ],
                'insurer' => $this->currentClaim->insurer?->client?->display_name,
            ] : null),
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'full_name' => $this->client->display_name,
                    'document_number' => $this->client->document_number,
                    'phone' => $this->client->phone,
                    'email' => $this->client->email,
                ];
            }),
            'vehicle' => $this->whenLoaded('vehicle', function () {
                $colorEnum = $this->vehicle->color instanceof VehicleColor
                    ? $this->vehicle->color
                    : VehicleColor::tryFrom((string) $this->vehicle->color);

                return [
                    'id' => $this->vehicle->id,
                    'plate' => $this->vehicle->plate,
                    'chassis' => $this->vehicle->chassis,
                    'brand' => $this->vehicle->relationLoaded('brand') ? $this->vehicle->brand?->name : null,
                    'model' => $this->vehicle->relationLoaded('vehicleModel') ? $this->vehicle->vehicleModel?->name : null,
                    'year' => $this->vehicle->year,
                    'color' => [
                        'value' => $colorEnum?->value,
                        'label' => $colorEnum?->label(),
                    ],
                    'engine_number' => $this->vehicle->engine_number,
                ];
            }),
            'service_categories' => $this->whenLoaded('serviceCategories', function () {
                return $this->serviceCategories->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                    ];
                });
            }),
            // El checklist se consulta y edita en reception-check-lists/{id}.
            'check_list' => $this->whenLoaded('checkList', function () {
                return $this->checkList ? [
                    'id' => $this->checkList->id,
                    'status' => $this->checkList->status,
                ] : null;
            }),
            'created_at' => $this->created_at,
        ];
    }
}

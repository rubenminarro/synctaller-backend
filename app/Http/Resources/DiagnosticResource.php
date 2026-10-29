<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\FuelLevel;

class DiagnosticResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reception_id' => $this->reception_id,
            'mechanic_id' => $this->mechanic_id,
            'customer_complaint' => $this->customer_complaint,
            'diagnosis' => $this->diagnosis,
            'recommendation' => $this->recommendation,
            'priority' => $this->priority,
            'status' => $this->status,
            'requires_parts' => $this->requires_parts,
            'requires_repair' => $this->requires_repair,
            'diagnosed_at' => $this->diagnosed_at,
            'reception' => $this->whenLoaded('reception', function () {

                $fuelEnum = $this->reception->fuel_level instanceof FuelLevel
                    ? $this->reception->fuel_level
                    : FuelLevel::tryFrom($this->reception->fuel_level);

                // Valores crudos (fechas Y-m-d, kilometraje entero): el frontend los formatea.
                return [
                    'reception_date' => $this->reception->reception_date?->toDateString(),
                    'estimated_delivery_date' => $this->reception->estimated_delivery_date?->toDateString(),
                    'mileage' => $this->reception->mileage,
                    'fuel' => [
                        'value' => $fuelEnum?->value,
                        'label' => $fuelEnum?->label(),
                    ],
                    'client' => $this->reception->relationLoaded('client')
                    ? [
                        'id' => $this->reception->client->id,
                        'full_name' => $this->reception->client->display_name,
                    ]
                    : null,
                    'vehicle' => $this->reception->relationLoaded('vehicle')
                    ? [
                        'id' => $this->reception->vehicle->id,
                        'plate' => $this->reception->vehicle->plate,
                        'brand' => $this->reception->vehicle->brand?->name,
                        'model' => $this->reception->vehicle->vehicleModel?->name,
                    ]
                    : null,
                ];
            }),
            'mechanic' => $this->whenLoaded('mechanic', function () {
                return [
                    'id' => $this->mechanic->id,
                    'name' => $this->mechanic->user->name,
                    'first_name' => $this->mechanic->user->first_name,
                    'last_name' => $this->mechanic->user->last_name,
                    ];
            }),
        ];
    }
}

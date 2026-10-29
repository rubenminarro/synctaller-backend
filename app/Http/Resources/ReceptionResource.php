<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\ReceptionStatus;
use App\Enums\VehicleColor;

class ReceptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        $statusEnum = $this->status instanceof ReceptionStatus
            ? $this->status
            : ReceptionStatus::tryFrom($this->status);

        // Fechas como Y-m-d (el frontend las formatea).
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'vehicle_id' => $this->vehicle_id,
            'reception_date' => $this->reception_date?->toDateString(),
            'estimated_delivery_date' => $this->estimated_delivery_date?->toDateString(),
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
                    'color' => [
                        'value' => $colorEnum?->value,
                        'label' => $colorEnum?->label()
                    ],
                ];
            }),
            'problem_description' => $this->problem_description,
            'status' => [
                'value' => $statusEnum?->value,
                'label' => $statusEnum?->label()
            ],
        ];
    }
}

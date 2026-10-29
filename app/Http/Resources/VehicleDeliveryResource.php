<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delivery_number' => $this->delivery_number,
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->label(),
            ],
            'reception_id' => $this->reception_id,
            'vehicle_id' => $this->vehicle_id,
            'plate' => $this->whenLoaded('vehicle', fn () => $this->vehicle?->plate),
            'client_id' => $this->client_id,
            'client_name' => $this->whenLoaded('client', fn () => $this->client?->display_name),
            'delivered_at' => $this->delivered_at,
            'final_mileage' => $this->final_mileage,
            'received_by_name' => $this->received_by_name,
            'created_at' => $this->created_at,
        ];
    }
}

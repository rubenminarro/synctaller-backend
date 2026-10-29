<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShowVehicleDeliveryResource extends JsonResource
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
            'reception' => $this->whenLoaded('reception', fn () => [
                'id' => $this->reception->id,
                'status' => $this->reception->status?->value,
                'reception_date' => $this->reception->reception_date?->toDateString(),
                'mileage' => $this->reception->mileage,
            ]),
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'plate' => $this->vehicle->plate,
                'mileage' => $this->vehicle->mileage,
            ]),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'full_name' => $this->client->display_name,
                'document_number' => $this->client->document_number,
            ]),
            'delivered_at' => $this->delivered_at,
            'delivered_by' => $this->delivered_by,
            'final_mileage' => $this->final_mileage,
            'fuel_level' => $this->fuel_level?->value,
            'received_by' => [
                'name' => $this->received_by_name,
                'document' => $this->received_by_document,
            ],
            'customer_accepted' => $this->customer_accepted,
            'signature_url' => $this->signatureUrl(),
            'observations' => $this->observations,
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $this->cancelled_by,
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

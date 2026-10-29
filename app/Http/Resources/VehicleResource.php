<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chassis' => $this->chassis,
            'plate' => $this->plate,
            'no_plate' => (bool) $this->no_plate,
            'year' => $this->year,
            'color' => $this->color,
            'engine_number' => $this->engine_number,
            'mileage' => $this->mileage,
            'fuel_type' => $this->fuel_type,
            'transmission' => $this->transmission,
            'notes' => $this->notes,
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'first_name' => $this->client->first_name,
                    'last_name' => $this->client->last_name,
                    'document_number' => $this->client->document_number,
                    'phone' => $this->client->phone,
                    'email' => $this->client->email,
                    'full_name' => $this->client->display_name,
                ];
            }),
            'brand' => $this->whenLoaded('brand', function () {
                return [
                    'id' => $this->brand->id,
                    'name' => $this->brand->name,
                ];
            }),
            'vehicle_model' => $this->whenLoaded('vehicleModel', function () {
                return [
                    'id' => $this->vehicleModel->id,
                    'name' => $this->vehicleModel->name,
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
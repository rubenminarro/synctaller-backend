<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShowClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => [
                'value' => $this->kind->value,
                'label' => $this->kind->label(),
            ],
            'document_number' => $this->document_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'business_name' => $this->business_name,
            'display_name' => $this->display_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'notifications_enabled' => $this->notifications_enabled,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'vehicles' => VehicleResource::collection(
                $this->whenLoaded('vehicles')
            ),
        ];
    }
}
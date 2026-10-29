<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShowInsurerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'client' => new ShowClientResource($this->whenLoaded('client')),
            'payment_term_days' => $this->payment_term_days,
            'notes' => $this->notes,
            'contacts' => InsurerContactResource::collection($this->whenLoaded('contacts')),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

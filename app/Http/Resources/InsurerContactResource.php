<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsurerContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'insurer_id' => $this->insurer_id,
            'name' => $this->name,
            'role' => [
                'value' => $this->role->value,
                'label' => $this->role->label(),
            ],
            'phone' => $this->phone,
            'email' => $this->email,
            'is_primary' => $this->is_primary,
            'notes' => $this->notes,
        ];
    }
}

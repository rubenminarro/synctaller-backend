<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiagnosticItemTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'service_category_id' => $this->service_category_id,
            'severity' => $this->severity,
            'requires_repair' => $this->requires_repair,
            'requires_replacement' => $this->requires_replacement,
            'estimated_time' => $this->estimated_time,
            'recommendation' => $this->recommendation,
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->label(),
            ],
            'category' => new ServiceCategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

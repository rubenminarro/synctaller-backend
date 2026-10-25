<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShowDiagnosticItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'diagnostic_id' => $this->diagnostic_id,
            'title' => $this->title,
            'description' => $this->description,
            'severity' => $this->severity,
            'status' => $this->status,
            'requires_repair' => $this->requires_repair,
            'requires_replacement' => $this->requires_replacement,
            'estimated_cost' => $this->estimated_cost,
            'estimated_time' => $this->estimated_time,
            'recommendation' => $this->recommendation,
            'diagnostic_item_template_id' => $this->diagnostic_item_template_id,
            'template' => DiagnosticItemResource::templateSummary($this),
            'diagnostic' => new DiagnosticResource(
                $this->whenLoaded('diagnostic')
            ),
            'photos' => DiagnosticItemPhotoResource::collection(
                $this->whenLoaded('photos')
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
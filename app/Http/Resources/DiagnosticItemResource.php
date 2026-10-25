<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiagnosticItemResource extends JsonResource
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
            'template' => self::templateSummary($this),
            'photos' => DiagnosticItemPhotoResource::collection(
                $this->whenLoaded('photos')
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Entrada del catálogo de la que salió el ítem (`null` si es libre). `deleted`: se eliminó del catálogo después.
     */
    public static function templateSummary(JsonResource $resource): mixed
    {
        return $resource->whenLoaded('template', fn ($template) => $template ? [
            'id' => $template->id,
            'code' => $template->code,
            'title' => $template->title,
            'deleted' => $template->trashed(),
        ] : null);
    }
}

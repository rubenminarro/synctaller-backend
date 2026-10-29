<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'budget_id' => $this->budget_id,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'responsibility' => [
                'value' => $this->responsibility->value,
                'label' => $this->responsibility->label(),
            ],
            'part_id' => $this->part_id,
            'part' => $this->whenLoaded('part', fn () => $this->part ? [
                'id' => $this->part->id,
                'code' => $this->part->code,
                'name' => $this->part->name,
            ] : null),
            'service_id' => $this->service_id,
            'service' => $this->whenLoaded('service', fn () => $this->service ? [
                'id' => $this->service->id,
                'code' => $this->service->code,
                'name' => $this->service->name,
            ] : null),
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'total' => $this->total,
            'estimated_hours' => $this->estimated_hours,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

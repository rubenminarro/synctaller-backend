<?php

namespace App\Http\Resources;

use App\Services\AllocationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Versión del reparto económico de una OT (M8). Importes sin IVA salvo el
 * deducible (tal como se informa). Trae el resumen por responsable y, en el
 * borrador, la vista previa de R1–R7 (`checks`).
 */
class WorkOrderAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(AllocationService::class);

        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'claim_id' => $this->claim_id,
            'version' => $this->version,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'sale_total' => $this->sale_total,
            'deductible_amount' => $this->deductible_amount,
            'notes' => $this->notes,
            'lines' => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'portion_type' => [
                    'value' => $line->portion_type->value,
                    'label' => $line->portion_type->label(),
                ],
                'source' => [
                    'type' => $line->source_type,
                    'id' => $line->source_id,
                ],
                'budget_item_id' => $line->budget_item_id,
                'client' => $line->client === null ? null : [
                    'id' => $line->client->id,
                    'display_name' => $line->client->display_name,
                ],
                'insurer_authorization_item_id' => $line->insurer_authorization_item_id,
                'internal_exception_id' => $line->internal_exception_id,
                'amount' => $line->amount,
                'description' => $line->description,
            ])->all(),
            'summary' => $service->summary($this->resource),
            // Vista previa de la confirmación: solo en el borrador.
            'checks' => $this->resource->isDraft() ? $service->checks($this->resource) : null,
            'created_by' => $this->creator === null ? null : ['id' => $this->creator->id, 'name' => $this->creator->name],
            'confirmed_by' => $this->confirmer === null ? null : ['id' => $this->confirmer->id, 'name' => $this->confirmer->name],
            'confirmed_at' => $this->confirmed_at,
            'superseded_by_id' => $this->superseded_by_id,
            'superseded_at' => $this->superseded_at,
            'created_at' => $this->created_at,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Autorización de la aseguradora (M6). Importes sin IVA, como el presupuesto.
 * Cada línea trae la copia del ítem presupuestado (descripción, cantidad y
 * total) con la que se registró: el ítem puede cambiar o borrarse al reabrir.
 */
class InsurerAuthorizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'budget_id' => $this->budget_id,
            'claim_id' => $this->claim_id,
            'version' => $this->version,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'result' => [
                'value' => $this->result->value,
                'label' => $this->result->label(),
            ],
            'authorization_number' => $this->authorization_number,
            'decided_at' => $this->decided_at?->format('Y-m-d'),
            'channel' => [
                'value' => $this->channel->value,
                'label' => $this->channel->label(),
            ],
            'adjuster_name' => $this->adjuster_name,
            'authorized_total' => $this->authorized_total,
            'notes' => $this->notes,
            'registered_by' => $this->whenLoaded('registrar', fn () => $this->registrar
                ? ['id' => $this->registrar->id, 'name' => $this->registrar->name]
                : null),
            'superseded_by_id' => $this->superseded_by_id,
            'superseded_at' => $this->superseded_at,
            'invalidated_at' => $this->invalidated_at,
            'invalidation_reason' => $this->invalidation_reason,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'budget_item_id' => $item->budget_item_id,
                'description' => $item->description,
                'budgeted_quantity' => $item->budgeted_quantity,
                'budgeted_total' => $item->budgeted_total,
                'decision' => [
                    'value' => $item->decision->value,
                    'label' => $item->decision->label(),
                ],
                'authorized_quantity' => $item->authorized_quantity,
                'authorized_unit_price' => $item->authorized_unit_price,
                'authorized_total' => $item->authorized_total,
                'notes' => $item->notes,
            ])->values()),
            'documents' => ClaimDocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at,
        ];
    }
}

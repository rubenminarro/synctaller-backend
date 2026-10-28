<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShowBudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'total' => $this->total,
            'notes' => $this->notes,
            'approved_at' => $this->approved_at,
            'reception_id' => $this->reception_id,
            'reception' => new ReceptionResource(
                $this->whenLoaded('reception')
            ),
            'created_by' => $this->created_by,
            'creator' => new UserResource(
                $this->whenLoaded('creator')
            ),
            'items' => BudgetItemResource::collection(
                $this->whenLoaded('items')
            ),
            'client_acceptances' => BudgetClientAcceptanceResource::collection(
                $this->whenLoaded('clientAcceptances')
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
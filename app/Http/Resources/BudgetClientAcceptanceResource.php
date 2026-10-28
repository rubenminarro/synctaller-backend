<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetClientAcceptanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => [
                'value' => $this->kind->value,
                'label' => $this->kind->label(),
            ],
            'decided_at' => $this->decided_at,
            'decided_by' => $this->decidedBy === null ? null : [
                'id' => $this->decidedBy->id,
                'name' => $this->decidedBy->name,
            ],
            'channel' => $this->channel === null ? null : [
                'value' => $this->channel->value,
                'label' => $this->channel->label(),
            ],
            'contact' => $this->contact,
            'notes' => $this->notes,
            'owner_amount' => $this->owner_amount,
        ];
    }
}

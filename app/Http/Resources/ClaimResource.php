<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaimResource extends JsonResource
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
            'claim_type' => [
                'value' => $this->claim_type->value,
                'label' => $this->claim_type->label(),
            ],
            'claim_number' => $this->claim_number,
            'loss_date' => $this->loss_date?->toDateString(),
            'insured_name' => $this->insured_name,
            'deductible_amount' => $this->deductible_amount,
            'deductible_billing_mode' => $this->deductible_billing_mode === null ? null : [
                'value' => $this->deductible_billing_mode->value,
                'label' => $this->deductible_billing_mode->label(),
            ],
            'reception_id' => $this->reception_id,
            'plate' => $this->whenLoaded('reception', fn () => $this->reception->vehicle?->plate),
            'insurer' => $this->whenLoaded('insurer', fn () => [
                'id' => $this->insurer->id,
                'code' => $this->insurer->code,
                'name' => $this->insurer->client?->display_name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}

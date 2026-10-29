<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShowClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = fn (?object $user) => $user ? ['id' => $user->id, 'name' => $user->name] : null;

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
            'policy_number' => $this->policy_number,
            'loss_date' => $this->loss_date?->toDateString(),
            'loss_description' => $this->loss_description,
            'reception' => $this->whenLoaded('reception', fn () => [
                'id' => $this->reception->id,
                'status' => [
                    'value' => $this->reception->status->value,
                    'label' => $this->reception->status->label(),
                ],
                'client' => $this->reception->client ? [
                    'id' => $this->reception->client->id,
                    'display_name' => $this->reception->client->display_name,
                ] : null,
                'vehicle' => $this->reception->vehicle ? [
                    'id' => $this->reception->vehicle->id,
                    'plate' => $this->reception->vehicle->plate,
                    'brand' => $this->reception->vehicle->brand?->name,
                    'model' => $this->reception->vehicle->vehicleModel?->name,
                ] : null,
            ]),
            'insurer' => $this->whenLoaded('insurer', fn () => [
                'id' => $this->insurer->id,
                'code' => $this->insurer->code,
                'name' => $this->insurer->client?->display_name,
                'document_number' => $this->insurer->client?->document_number,
                'status' => $this->insurer->status->value,
            ]),
            'insured' => [
                'client_id' => $this->insured_client_id,
                'name' => $this->insured_name,
                'document' => $this->insured_document,
                'phone' => $this->insured_phone,
                'email' => $this->insured_email,
            ],
            'adjuster' => [
                'name' => $this->adjuster_name,
                'phone' => $this->adjuster_phone,
                'email' => $this->adjuster_email,
                'inspection_scheduled_at' => $this->inspection_scheduled_at,
                'inspected_at' => $this->inspected_at,
            ],
            'deductible_amount' => $this->deductible_amount,
            'coverage_notes' => $this->coverage_notes,
            'closed_at' => $this->closed_at,
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $this->whenLoaded('canceller', fn () => $user($this->canceller)),
            'cancellation_reason' => $this->cancellation_reason,
            'created_by' => $this->whenLoaded('creator', fn () => $user($this->creator)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

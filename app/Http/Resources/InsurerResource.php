<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsurerResource extends JsonResource
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
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'display_name' => $this->client->display_name,
                'document_number' => $this->client->document_number,
                'email' => $this->client->email,
                'phone' => $this->client->phone,
            ]),
            'payment_term_days' => $this->payment_term_days,
            'deductible_billing_mode' => $this->deductible_billing_mode === null ? null : [
                'value' => $this->deductible_billing_mode->value,
                'label' => $this->deductible_billing_mode->label(),
            ],
            'contacts_count' => $this->whenCounted('contacts'),
            'created_at' => $this->created_at,
        ];
    }
}

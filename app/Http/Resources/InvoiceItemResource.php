<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => [
                'type' => $this->source_type,
                'id' => $this->source_id,
            ],
            'allocation_line_id' => $this->allocation_line_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'tax_rate' => [
                'value' => $this->tax_rate?->value,
                'label' => $this->tax_rate?->label(),
            ],
            'base_unit_price' => $this->base_unit_price,
            'unit_price' => $this->unit_price,
            'gross_amount' => $this->grossAmount(),
            'discount' => $this->discount,
            'total' => $this->total,
        ];
    }
}

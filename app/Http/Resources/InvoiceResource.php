<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->label(),
            ],
            'work_order_id' => $this->work_order_id,
            'work_order_code' => $this->whenLoaded('workOrder', fn () => $this->workOrder?->code),
            // Versión del reparto que factura (M9); null en el flujo particular.
            'allocation_id' => $this->allocation_id,
            'client_id' => $this->client_id,
            'customer_name' => $this->customer_name,
            'customer_tax_id' => $this->customer_tax_id,
            'condition' => [
                'value' => $this->condition?->value,
                'label' => $this->condition?->label(),
            ],
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'total' => $this->total,
            'balance' => $this->balance(),
            'payment_status' => $this->paymentStatus()?->value,
            'created_at' => $this->created_at,
        ];
    }
}

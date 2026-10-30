<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShowInvoiceResource extends JsonResource
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
            'stamp' => [
                'id' => $this->invoice_stamp_id,
                'stamp_number' => $this->stamp_number,
                'valid_until' => $this->stamp_valid_until?->toDateString(),
            ],
            'customer' => [
                'name' => $this->customer_name,
                'tax_id' => $this->customer_tax_id,
                'address' => $this->customer_address,
            ],
            'condition' => [
                'value' => $this->condition?->value,
                'label' => $this->condition?->label(),
            ],
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'totals' => [
                'exempt' => $this->total_exempt,
                'taxed_5' => $this->total_5,
                'taxed_10' => $this->total_10,
                'iva_5' => $this->iva_5,
                'iva_10' => $this->iva_10,
                'iva_total' => bcadd($this->iva_5, $this->iva_10, 2),
                'discount' => $this->discount_total,
                'total' => $this->total,
            ],
            'payment_summary' => [
                'paid_total' => $this->paidTotal(),
                'credited_total' => $this->creditedTotal(),
                'balance' => $this->balance(),
                'status' => $this->paymentStatus()?->value,
            ],
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            // Notas de crédito emitidas fuera del sistema (P10), también las anuladas.
            'credit_notes' => InvoiceCreditNoteResource::collection($this->whenLoaded('creditNotes')),
            'issued_at' => $this->issued_at,
            'issued_by' => $this->issued_by,
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $this->cancelled_by,
            'cancellation_reason' => $this->cancellation_reason,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

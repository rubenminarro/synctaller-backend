<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Nota de crédito emitida fuera del sistema y registrada sobre una factura (P10).
 * Importes con IVA, en guaraníes, salvo la base de cada línea (sin IVA).
 */
class InvoiceCreditNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'number' => $this->number,
            'stamp_number' => $this->stamp_number,
            'issue_date' => $this->issue_date?->format('Y-m-d'),
            'reason' => $this->reason,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'total' => $this->total,
            'total_exempt' => $this->total_exempt,
            'total_5' => $this->total_5,
            'total_10' => $this->total_10,
            'iva_5' => $this->iva_5,
            'iva_10' => $this->iva_10,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'invoice_item_id' => $item->invoice_item_id,
                'description' => $item->description,
                'tax_rate' => $item->tax_rate->value,
                'amount' => $item->amount,
                'base_amount' => $item->base_amount,
            ])->all()),
            'registered_by' => $this->registrar === null ? null : ['id' => $this->registrar->id, 'name' => $this->registrar->name],
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $this->canceller === null ? null : ['id' => $this->canceller->id, 'name' => $this->canceller->name],
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at,
        ];
    }
}

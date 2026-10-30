<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una visita (recepción) del historial del vehículo. Costos y margen solo
 * con work-orders.view-economics.
 */
class VehicleHistoryReceptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $withEconomics = (bool) $request->user()?->can('work-orders.view-economics');
        $delivery = $this->deliveries->first();

        return [
            'reception_id' => $this->id,
            'reception_date' => $this->reception_date?->toDateString(),
            'status' => $this->status?->value,
            'mileage_in' => $this->mileage,
            'fuel_level' => $this->fuel_level,
            'problem_description' => $this->problem_description,
            'service_categories' => $this->serviceCategories->pluck('name')->values(),
            'diagnostics' => $this->diagnostics->map(fn ($diagnostic) => [
                'id' => $diagnostic->id,
                'diagnosed_at' => $diagnostic->diagnosed_at,
                'diagnosis' => $diagnostic->diagnosis,
                'recommendation' => $diagnostic->recommendation,
                'priority' => $diagnostic->priority,
                'findings' => $diagnostic->items->map(fn ($item) => [
                    'title' => $item->title,
                    'severity' => $item->severity,
                    'status' => $item->status,
                ])->values(),
            ])->values(),
            'budgets' => $this->budgets->map(fn ($budget) => [
                'id' => $budget->id,
                'code' => $budget->code,
                'status' => $budget->status?->value,
                'total' => $budget->total,
            ])->values(),
            'work_orders' => $this->workOrders->map(fn ($workOrder) => $this->workOrder($workOrder, $withEconomics))->values(),
            'delivery' => $delivery ? [
                'delivery_number' => $delivery->delivery_number,
                'delivered_at' => $delivery->delivered_at,
                'mileage_out' => $delivery->final_mileage,
                'received_by' => $delivery->received_by_name,
            ] : null,
        ];
    }

    /**
     * $this->when() no se resuelve dentro de colecciones anidadas: la clave
     * economics se agrega a mano solo si corresponde.
     */
    private function workOrder($workOrder, bool $withEconomics): array
    {
        $data = [
            'id' => $workOrder->id,
            'code' => $workOrder->code,
            'status' => $workOrder->status?->value,
            'completed_at' => $workOrder->completed_at,
            'mechanic' => $workOrder->mechanic?->fullName(),
            'jobs' => $workOrder->items->map(fn ($item) => [
                'description' => $item->description,
                'type' => $item->type?->value,
                'status' => $item->status?->value,
            ])->values(),
            'parts_used' => $workOrder->parts
                ->filter(fn ($line) => bccomp($line->netQuantity(), '0', 3) > 0)
                ->map(fn ($line) => [
                    'part_code' => $line->part?->code,
                    'name' => $line->part?->name,
                    'quantity' => $line->netQuantity(),
                ])->values(),
            'invoice' => $workOrder->currentInvoice ? [
                'number' => $workOrder->currentInvoice->number,
                'status' => $workOrder->currentInvoice->status?->value,
                'total' => $workOrder->currentInvoice->total,
                'balance' => $workOrder->currentInvoice->balance(),
                'payment_status' => $workOrder->currentInvoice->paymentStatus()?->value,
            ] : null,
            // Todas las facturas vigentes, con su destinatario (M9, F); `invoice` queda como clave heredada.
            'invoices' => ShowWorkOrderResource::invoices($workOrder->currentInvoices()->get()),
        ];

        if ($withEconomics && $workOrder->closing) {
            $data['economics'] = [
                'sale_total' => $workOrder->closing->sale_total,
                'cost_total' => $workOrder->closing->cost_total,
                'gross_margin' => $workOrder->closing->gross_margin,
                'is_cost_complete' => $workOrder->closing->is_cost_complete,
            ];
        }

        return $data;
    }
}

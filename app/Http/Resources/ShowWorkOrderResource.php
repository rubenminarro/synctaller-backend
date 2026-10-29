<?php

namespace App\Http\Resources;

use App\Services\WorkOrderClosingService;
use App\Services\OwnerShareService;
use App\Services\WorkOrderCommissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShowWorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'reception_id' => $this->reception_id,
            'budget_id' => $this->budget_id,
            'mechanic_id' => $this->mechanic_id,
            'created_by' => $this->created_by,
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->label(),
            ],
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'notes' => $this->notes,
            'reception' => new ReceptionResource(
                $this->whenLoaded('reception')
            ),
            'budget' => new BudgetResource(
                $this->whenLoaded('budget')
            ),
            'mechanic' => new MechanicResource(
                $this->whenLoaded('mechanic')
            ),
            'creator' => new UserResource(
                $this->whenLoaded('creator')
            ),
            'items' => WorkOrderItemResource::collection(
                $this->whenLoaded('items')
            ),
            'commission_summary' => $this->when(
                $this->relationLoaded('items'),
                fn () => app(WorkOrderCommissionService::class)->summary($this->resource)
            ),
            'labor_summary' => $this->when(
                $this->relationLoaded('items'),
                fn () => app(WorkOrderCommissionService::class)->laborSummary($this->resource)
            ),
            'parts' => WorkOrderPartResource::collection(
                $this->whenLoaded('parts')
            ),
            'parts_summary' => $this->whenLoaded('parts', fn () => [
                'total_cost' => $this->parts->reduce(
                    fn (string $sum, $line) => bcadd($sum, $line->totalCost() ?? '0', 2),
                    '0.00'
                ),
                'total_price' => $this->parts->reduce(
                    fn (string $sum, $line) => bcadd($sum, $line->totalPrice(), 2),
                    '0.00'
                ),
                'unresolved_count' => $this->parts->filter(
                    fn ($line) => $line->status->isOpen()
                )->count(),
            ]),
            // Factura vigente (borrador o emitida): solo con permiso de facturas.
            'invoice' => $this->when(
                $this->relationLoaded('currentInvoice') && (bool) $request->user()?->can('invoices.show'),
                fn () => $this->currentInvoice ? [
                    'id' => $this->currentInvoice->id,
                    'number' => $this->currentInvoice->number,
                    'status' => [
                        'value' => $this->currentInvoice->status->value,
                        'label' => $this->currentInvoice->status->label(),
                    ],
                    'total' => $this->currentInvoice->total,
                ] : null
            ),
            // Costos y margen: solo con permiso (no visibles para el mecánico).
            'economic_summary' => $this->when(
                $this->relationLoaded('items') &&
                $this->relationLoaded('parts') &&
                (bool) $request->user()?->can('work-orders.view-economics'),
                fn () => app(WorkOrderClosingService::class)->summary($this->resource)
            ),
            // Siniestro abierto en la recepción (M7): el frontend avisa que un extra queda a cargo del dueño (P5).
            'has_claim' => app(OwnerShareService::class)->openClaim($this->reception_id) !== null,
            // Advertencia de B (M7): la parte potencial del dueño supera lo que aceptó, o subió el deducible. Solo con
            // claims.show (el mecánico ve la OT, pero no los datos del siniestro); null si no hay nada que advertir.
            'owner_amount_warning' => $this->when(
                $this->relationLoaded('items') &&
                $this->relationLoaded('parts') &&
                (bool) $request->user()?->can('claims.show'),
                fn () => app(OwnerShareService::class)->workOrderWarning($this->resource)
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
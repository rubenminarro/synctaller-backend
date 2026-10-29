<?php

namespace App\Services;

use App\Enums\WorkOrderItemStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderPart;
use Illuminate\Support\Collection;

/**
 * Lo vendido en una OT, concepto por concepto (sin IVA): la mano de obra, los
 * servicios y los repuestos fuera de catálogo por lo presupuestado (salvo los
 * ítems cancelados), y los repuestos de catálogo, extras incluidos, por lo
 * realmente usado. Suma la venta del cierre. Lo usan la factura (las líneas,
 * InvoiceService) y el reparto (las porciones, M8), para que no diverjan.
 */
class WorkOrderSaleBreakdown
{
    /**
     * @return Collection<int, array{source_type: string, source_id: int, source: WorkOrderItem|WorkOrderPart, budget_item_id: int|null, is_extra: bool, description: string, quantity: string, base_unit_price: string, base_total: string}>
     */
    public function concepts(WorkOrder $workOrder): Collection
    {
        $workOrder->loadMissing(['items.budgetItem', 'parts.part', 'parts.workOrderItem']);
        $concepts = collect();

        foreach ($workOrder->items as $item) {
            $budgetItem = $item->budgetItem;

            if ($item->status === WorkOrderItemStatus::CANCELLED || $budgetItem === null) {
                continue;
            }

            // Los repuestos de catálogo se venden por lo consumido (abajo).
            if (!$item->isCommissionable() && $budgetItem->part_id !== null) {
                continue;
            }

            $concepts->push([
                'source_type' => $item->getMorphClass(),
                'source_id' => $item->id,
                'source' => $item,
                'budget_item_id' => $budgetItem->id,
                'is_extra' => false,
                'description' => $item->description,
                'quantity' => $budgetItem->quantity,
                'base_unit_price' => $budgetItem->unit_price,
                'base_total' => $budgetItem->total,
            ]);
        }

        foreach ($workOrder->parts as $line) {
            $net = $line->netQuantity();

            if (bccomp($net, '0', 3) === 0) {
                continue;
            }

            $concepts->push([
                'source_type' => $line->getMorphClass(),
                'source_id' => $line->id,
                'source' => $line,
                'budget_item_id' => $line->workOrderItem?->budget_item_id,
                'is_extra' => $line->isExtra(),
                'description' => $line->part->name,
                'quantity' => $net,
                'base_unit_price' => $line->unit_price,
                'base_total' => $line->totalPrice(),
            ]);
        }

        return $concepts;
    }
}

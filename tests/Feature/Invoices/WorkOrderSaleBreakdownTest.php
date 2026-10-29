<?php

namespace Tests\Feature\Invoices;

use App\Models\InvoiceItem;
use App\Models\WorkOrder;
use App\Services\WorkOrderSaleBreakdown;
use App\Support\Decimal;

/**
 * Detalle por concepto de lo vendido en una OT (M8): lo comparten la factura y el
 * reparto. Debe dar exactamente las líneas que la factura generaba antes del
 * refactor y sumar la venta del cierre.
 */
class WorkOrderSaleBreakdownTest extends InvoiceTestCase
{
    public function test_the_breakdown_matches_the_invoice_lines_and_the_closing(): void
    {
        $this->actingAsAdmin();

        $oil = $this->createPartViaApi(null, ['unit' => 'liter']);
        $clip = $this->createPartViaApi();
        $disc = $this->createPartViaApi();
        foreach ([$oil, $clip, $disc] as $part) {
            $this->entry($part['id'], 10, 100, ['type' => 'initial'])->assertCreated();
        }

        $budgetId = $this->createBudgetWithItems([
            ['type' => 'part', 'part_id' => $oil['id'], 'description' => 'Aceite', 'quantity' => 2, 'unit_price' => 150],
            ['type' => 'labor', 'description' => 'Cambio de embrague', 'quantity' => 1, 'unit_price' => 200000],
            ['type' => 'part', 'description' => 'Tornillos', 'quantity' => 3, 'unit_price' => 5000],
            ['type' => 'labor', 'description' => 'Lavado', 'quantity' => 1, 'unit_price' => 30000],
        ]);
        $this->approveBudget($budgetId);
        $workOrder = $this->createWorkOrder($budgetId);
        $this->startWorkOrder($workOrder);
        $url = "/api/work-orders/{$workOrder['id']}";

        // Aceite: se usa menos de lo presupuestado. Un extra aprobado y consumido, otro rechazado. El lavado se cancela.
        $this->postJson($this->partsUrl($workOrder, $workOrder['parts'][0]['id'], 'consume'), ['quantity_used' => 1.7])->assertOk();
        $extra = $this->addApprovedExtra($workOrder, ['part_id' => $clip['id'], 'quantity' => 4, 'unit_price' => 5000]);
        $this->postJson($this->partsUrl($workOrder, $extra['id'], 'consume'), ['quantity_used' => 4])->assertOk();
        $rejected = $this->postJson($this->partsUrl($workOrder), ['part_id' => $disc['id'], 'quantity' => 1])->assertCreated()->json('data');
        $this->postJson($this->partsUrl($workOrder, $rejected['id'], 'reject'), ['channel' => 'phone', 'notes' => 'No lo quiere.'])->assertOk();

        foreach ($workOrder['items'] as $item) {
            if ($item['description'] === 'Lavado') {
                $this->postJson("{$url}/items/{$item['id']}/cancel")->assertOk();
                continue;
            }
            $this->postJson("{$url}/items/{$item['id']}/start")->assertOk();
            $this->postJson("{$url}/items/{$item['id']}/complete")->assertOk();
        }
        $this->postJson("{$url}/complete")->assertOk();

        $invoice = $this->generateInvoice($workOrder);
        $model = WorkOrder::with(['items.budgetItem', 'parts.part', 'closing'])->findOrFail($workOrder['id']);
        $concepts = app(WorkOrderSaleBreakdown::class)->concepts($model);

        $fromInvoice = InvoiceItem::where('invoice_id', $invoice['id'])->orderBy('id')->get()->map(fn (InvoiceItem $line) => [
            $line->source_type, $line->source_id, $line->description,
            Decimal::of($line->quantity, 3), Decimal::of($line->base_unit_price, 2), Decimal::of($line->base_total, 2),
        ])->all();
        $fromBreakdown = $concepts->map(fn (array $concept) => [
            $concept['source_type'], $concept['source_id'], $concept['description'],
            Decimal::of($concept['quantity'], 3), Decimal::of($concept['base_unit_price'], 2), Decimal::of($concept['base_total'], 2),
        ])->all();

        $this->assertSame($fromInvoice, $fromBreakdown);
        $this->assertCount(4, $fromInvoice);
        $this->assertSame((string) $model->closing->sale_total, $concepts->reduce(fn (string $sum, array $c) => bcadd($sum, $c['base_total'], 2), '0.00'));

        // Cada concepto conoce su ítem del presupuesto; los extras no tienen.
        $this->assertSame([false, false, false, true], $concepts->pluck('is_extra')->all());
        $this->assertNull($concepts->last()['budget_item_id']);
        $this->assertNotNull($concepts->first()['budget_item_id']);
    }
}

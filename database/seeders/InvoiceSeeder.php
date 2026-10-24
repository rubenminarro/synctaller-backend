<?php

namespace Database\Seeders;

use App\Enums\InvoiceCondition;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Database\Seeder;

/**
 * Facturas de los trabajos terminados (ExampleScenarios::BILLING), generadas
 * desde la OT y emitidas por la caja el día que se completó: al contado, a
 * crédito (vencidas o por vencer), en borrador, y una anulada y vuelta a
 * facturar. La numeración sale del timbrado de InvoiceStampSeeder.
 */
class InvoiceSeeder extends Seeder
{
    private const CASHIERS = ['caja1@mail.com.py', 'caja2@mail.com.py'];

    public function run(InvoiceService $invoiceService): void
    {
        foreach (ExampleScenarios::chronological() as $index => $scenario) {
            $workOrder = isset($scenario['billing']) ? BudgetSeeder::of($scenario)?->workOrder : null;

            // Idempotente: una OT ya facturada (aun con la factura anulada) no se vuelve a facturar.
            if (!$workOrder || Invoice::where('work_order_id', $workOrder->id)->exists()) {
                continue;
            }

            $billing = $scenario['billing'];
            $cashier = ExampleScenarios::user(self::CASHIERS[$index % 2]);
            $at = fn (int $offset, int $hour) => ExampleScenarios::afterCompletion($scenario, $offset, $hour);

            $issue = function (int $hour) use ($invoiceService, $workOrder, $cashier, $billing, $at) {
                return ExampleScenarios::travel($at(0, $hour), function () use ($invoiceService, $workOrder, $cashier, $billing) {
                    $invoice = $invoiceService->generate($workOrder, $cashier);

                    if ($billing['invoice'] === 'draft') {
                        return $invoice;
                    }

                    if ($billing['invoice'] === 'credit') {
                        $invoice = $invoiceService->update($invoice, [
                            'condition' => InvoiceCondition::CREDIT->value,
                            'due_date' => now()->addDays($billing['due'])->toDateString(),
                        ]);
                    }

                    return $invoiceService->issue($invoice, null, $cashier);
                });
            };

            $invoice = $issue(18);

            if (isset($billing['reissue'])) {
                ExampleScenarios::travel($at(0, 19), fn () => $invoiceService->cancel($invoice, $billing['reissue'], $cashier));
                $issue(20);
            }
        }
    }

    /**
     * Factura vigente (no anulada) del escenario.
     */
    public static function of(array $scenario): ?Invoice
    {
        $workOrder = BudgetSeeder::of($scenario)?->workOrder;

        return $workOrder ? Invoice::where('work_order_id', $workOrder->id)->current()->first() : null;
    }
}

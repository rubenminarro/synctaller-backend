<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\CreditNoteService;
use Illuminate\Database\Seeder;

/**
 * Notas de crédito emitidas fuera del sistema y registradas por el cajero (P10).
 * H13 (a crédito, sin cobros) queda acreditada por completo: el cliente reclamó
 * el trabajo y se le acreditó todo. H07 (a crédito, cobrada a medias) tiene una
 * nota parcial sobre su primera línea y otra anulada por haberse cargado mal.
 * Va después de los cobros. Idempotente: omite la factura que ya tiene notas.
 */
class CreditNoteSeeder extends Seeder
{
    private const CASHIER = 'caja1@mail.com.py';

    public function run(CreditNoteService $creditNoteService): void
    {
        $cashier = ExampleScenarios::user(self::CASHIER);

        foreach (ExampleScenarios::chronological() as $scenario) {
            if (!in_array($scenario['key'], ['H07', 'H13'], true)) {
                continue;
            }

            $invoice = InvoiceSeeder::of($scenario);

            if (!$invoice || $invoice->creditNotes()->exists()) {
                continue;
            }

            $number = fn (int $n) => '001-002-' . str_pad((string) ($invoice->id * 10 + $n), 7, '0', STR_PAD_LEFT);

            if ($scenario['key'] === 'H13') {
                ExampleScenarios::travel(ExampleScenarios::afterCompletion($scenario, 3, 15), fn () => $creditNoteService->register($invoice->refresh(), [
                    'number' => $number(1),
                    'issue_date' => now()->toDateString(),
                    'reason' => 'El cliente reclamó el trabajo: se le acredita la factura completa.',
                    'items' => $invoice->items->map(fn (InvoiceItem $item) => ['invoice_item_id' => $item->id, 'amount' => (int) $item->total])->all(),
                ], $cashier));

                continue;
            }

            // H07: la mitad de lo que le queda a la primera línea, sin pasar el saldo.
            ExampleScenarios::travel(ExampleScenarios::afterCompletion($scenario, 8, 15), function () use ($creditNoteService, $invoice, $cashier, $number) {
                $item = $invoice->items()->orderBy('id')->firstOrFail();
                $amount = (int) bcdiv(min((string) $item->total, $invoice->refresh()->balance()), '2', 0);

                $creditNoteService->register($invoice, [
                    'number' => $number(1),
                    'issue_date' => now()->toDateString(),
                    'reason' => 'Descuento acordado por la demora en la entrega.',
                    'items' => [['invoice_item_id' => $item->id, 'amount' => $amount]],
                ], $cashier);

                $wrong = $creditNoteService->register($invoice->refresh(), [
                    'number' => $number(2),
                    'issue_date' => now()->toDateString(),
                    'reason' => 'Descuento acordado por la demora en la entrega.',
                    'items' => [['invoice_item_id' => $item->id, 'amount' => 1000]],
                ], $cashier);

                $creditNoteService->cancel($wrong, 'Se cargó dos veces.', $cashier);
            });
        }
    }
}

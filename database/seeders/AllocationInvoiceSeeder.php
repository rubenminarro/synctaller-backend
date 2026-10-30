<?php

namespace Database\Seeders;

use App\Enums\InvoiceCondition;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Database\Seeder;

/**
 * Facturas por responsable desde el reparto confirmado (M9), por los Services
 * con el cajero: una a la aseguradora (a crédito, queda impaga) y otra al dueño
 * (al contado, cobrada). Es el escenario obligatorio de la especificación: un
 * siniestro con reparto confirmado y dos facturas (C05, con deducible 0). Va
 * después de InvoiceSeeder (timbrado). Idempotente: omite el reparto que ya
 * tiene facturas.
 */
class AllocationInvoiceSeeder extends Seeder
{
    private const CASHIER = 'caja1@mail.com.py';

    public function run(InvoiceService $invoiceService, PaymentService $paymentService): void
    {
        $cashier = ExampleScenarios::user(self::CASHIER);

        foreach (ExampleScenarios::chronological() as $scenario) {
            $workOrder = isset($scenario['work_order']) ? BudgetSeeder::of($scenario)?->workOrder : null;
            $allocation = $workOrder instanceof WorkOrder ? $workOrder->confirmedAllocation()->first() : null;

            if (!$allocation || Invoice::where('allocation_id', $allocation->id)->exists()) {
                continue;
            }

            // Sin DISTINCT en SQL: la relación ordena por id y MySQL no admite ordenar por una columna fuera del SELECT.
            $clients = $allocation->lines()->whereNotNull('client_id')->pluck('client_id')->unique()->values();

            foreach ($clients as $number => $clientId) {
                ExampleScenarios::travel(ExampleScenarios::afterCompletion($scenario, 2, 9 + $number), function () use ($invoiceService, $paymentService, $allocation, $clientId, $cashier) {
                    $invoice = $invoiceService->issue($invoiceService->generateForAllocation($allocation, $clientId, $cashier), null, $cashier);

                    // La del dueño, al contado, se cobra al emitirla; la de la aseguradora, a crédito, queda impaga.
                    if ($invoice->condition === InvoiceCondition::CASH) {
                        $paymentService->register($invoice->refresh(), [
                            'amount' => (int) $invoice->balance(),
                            'method' => PaymentMethod::TRANSFER->value,
                            'reference' => sprintf('TRA-%06d', $invoice->id),
                            'bank' => 'Banco Continental',
                        ], $cashier);
                    }
                });
            }
        }
    }
}

<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Services\SupplierPaymentService;
use Illuminate\Database\Seeder;

/**
 * Pagos a proveedores de las compras recibidas (PurchaseSeeder), registrados
 * por la caja y repartidos en los últimos meses (no mueven stock): totales,
 * parciales y uno anulado. Quedan cuentas por pagar vencidas (P3 con saldo, P4
 * sin pagar) y una por vencer (P6).
 */
class SupplierPaymentSeeder extends Seeder
{
    /**
     * Referencia de la compra => pagos [días atrás, método, monto (full|half|rest), anulado con motivo (opcional)].
     */
    private const PAYMENTS = [
        'P1' => [[97, 'transfer', 'full']],
        'P2' => [[80, 'transfer', 'half'], [60, 'check', 'rest']],
        'P3' => [[75, 'transfer', 'half', 'Se transfirió a la cuenta equivocada.'], [70, 'check', 'half']],
        'P6' => [[30, 'cash', 'half']],
    ];

    public function run(SupplierPaymentService $supplierPaymentService): void
    {
        $cashiers = ['caja1@mail.com.py', 'caja2@mail.com.py'];

        foreach (self::PAYMENTS as $key => $payments) {
            $purchase = PurchaseSeeder::received($key);

            // Idempotente: una compra que ya tiene pagos (aun anulados) no se vuelve a pagar.
            if (!$purchase || $purchase->payments()->exists()) {
                continue;
            }

            foreach ($payments as $number => [$days, $method, $amount]) {
                $cancelReason = $payments[$number][3] ?? null;
                $user = ExampleScenarios::user($cashiers[$number % 2]);
                $date = ExampleScenarios::at(['days' => $days], 0, 14);

                ExampleScenarios::travel($date, function () use ($supplierPaymentService, $purchase, $method, $amount, $cancelReason, $user, $date, $key, $number) {
                    $balance = (int) $purchase->refresh()->balance();
                    $method = PaymentMethod::from($method);

                    $payment = $supplierPaymentService->register($purchase, [
                        'amount' => $amount === 'half' ? intdiv($balance, 2) : $balance,
                        'method' => $method->value,
                        'payment_date' => $date->toDateString(),
                        'reference' => $method === PaymentMethod::CASH ? null : sprintf('%s-%s%d', strtoupper(substr($method->value, 0, 3)), $key, $number),
                        'bank' => in_array($method, [PaymentMethod::TRANSFER, PaymentMethod::CHECK], true) ? 'Banco Itaú' : null,
                        'supplier_receipt_number' => sprintf('REC-%s-%d', $key, $number + 1),
                    ], $user);

                    if ($cancelReason) {
                        $supplierPaymentService->cancel($payment, $cancelReason, $user);
                    }
                });
            }
        }
    }
}

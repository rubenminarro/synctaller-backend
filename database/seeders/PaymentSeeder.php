<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Services\PaymentService;
use Illuminate\Database\Seeder;

/**
 * Cobros de las facturas emitidas (ExampleScenarios::BILLING) con todos los
 * medios de pago: totales, parciales (las de crédito generan recibo), en
 * efectivo con vuelto, y uno anulado y vuelto a cobrar. Las facturas sin cobros
 * quedan pendientes; a crédito y con el vencimiento pasado, vencidas.
 */
class PaymentSeeder extends Seeder
{
    private const CASHIERS = ['caja1@mail.com.py', 'caja2@mail.com.py'];

    public function run(PaymentService $paymentService): void
    {
        foreach (ExampleScenarios::chronological() as $index => $scenario) {
            $invoice = !empty($scenario['billing']['payments']) ? InvoiceSeeder::of($scenario) : null;

            // Idempotente: una factura que ya tiene cobros (aun anulados) no se vuelve a cobrar.
            if (!$invoice || $invoice->payments()->exists()) {
                continue;
            }

            $cashier = ExampleScenarios::user(self::CASHIERS[$index % 2]);

            foreach ($scenario['billing']['payments'] as $number => [$day, $method, $amount]) {
                $cancelReason = $scenario['billing']['payments'][$number][3] ?? null;

                ExampleScenarios::travel(
                    ExampleScenarios::afterCompletion($scenario, $day, 10 + $number),
                    function () use ($paymentService, $invoice, $cashier, $method, $amount, $cancelReason, $number) {
                        $payment = $paymentService->register($invoice->refresh(), self::paymentData($invoice, $method, $amount, $number), $cashier);

                        if ($cancelReason) {
                            $paymentService->cancel($payment, $cancelReason, $cashier);
                        }
                    }
                );
            }
        }
    }

    /**
     * Datos del cobro como los envía la API: monto según el saldo, y la referencia o el banco que pide cada medio.
     */
    private static function paymentData(Invoice $invoice, string $method, string $amount, int $number): array
    {
        $balance = (int) $invoice->balance();
        $value = $amount === 'half' ? intdiv($balance, 2) : $balance;
        $method = PaymentMethod::from($method);
        $reference = sprintf('%s-%06d', strtoupper(substr($method->value, 0, 3)), $invoice->id * 10 + $number);

        return match ($method) {
            // En efectivo se paga con billetes grandes: se registra el vuelto.
            PaymentMethod::CASH => ['amount' => $value, 'method' => $method->value, 'amount_received' => (int) (ceil($value / 50000) * 50000) ?: $value],
            PaymentMethod::CARD => ['amount' => $value, 'method' => $method->value, 'reference' => $reference],
            PaymentMethod::TRANSFER, PaymentMethod::CHECK => ['amount' => $value, 'method' => $method->value, 'reference' => $reference, 'bank' => 'Banco Continental'],
        };
    }
}

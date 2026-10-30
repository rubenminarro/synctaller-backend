<?php

namespace App\Services;

use App\Enums\BudgetStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\TaxRate;
use App\Enums\VehicleDeliveryStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\PaymentException;
use App\Models\Budget;
use App\Models\Claim;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Vehicle;
use App\Models\VehicleDelivery;
use App\Support\Format;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Comprobantes imprimibles (FASE 24): presupuesto, factura, recibo y entrega.
 * Los borradores y anulados se imprimen con marca de agua. La factura es una
 * representación: no reemplaza al comprobante fiscal.
 */
class DocumentPdfService
{
    public function __construct(
        private readonly BrandingService $brandingService
    ) {
    }

    /**
     * @return array{filename: string, content: string}
     */
    public function budget(Budget $budget): array
    {
        $budget->loadMissing(['items', 'reception.client', 'reception.vehicle.brand', 'reception.vehicle.vehicleModel']);

        return $this->render('pdf.budget', "presupuesto-{$budget->code}.pdf", [
            'title' => "Presupuesto {$budget->code}",
            'watermark' => match ($budget->status) {
                BudgetStatus::DRAFT => 'BORRADOR',
                BudgetStatus::REJECTED => 'RECHAZADO',
                BudgetStatus::CANCELLED => 'CANCELADO',
                default => null,
            },
            'budget' => $budget,
            'client' => $this->client($budget->reception?->client),
            'vehicle' => $this->vehicle($budget->reception?->vehicle),
            'taxRate' => (int) config('invoice.default_tax_rate', 10),
            'items' => $budget->items->map(fn ($item) => [
                'type' => $item->type->label(),
                'description' => $item->description,
                'quantity' => Format::quantity($item->quantity, 2),
                'unit_price' => Format::integer($item->unit_price),
                'total' => Format::integer($item->total),
            ]),
        ]);
    }

    /**
     * @return array{filename: string, content: string}
     */
    public function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'workOrder.reception.vehicle.brand', 'workOrder.reception.vehicle.vehicleModel']);

        $number = $invoice->number ?? "borrador-{$invoice->id}";

        return $this->render('pdf.invoice', "factura-{$number}.pdf", [
            'title' => $invoice->number ? "Factura {$invoice->number}" : 'Factura (borrador)',
            'watermark' => match ($invoice->status) {
                InvoiceStatus::DRAFT => 'BORRADOR',
                InvoiceStatus::CANCELLED => 'ANULADA',
                default => null,
            },
            'invoice' => $invoice,
            'vehicle' => $this->vehicle($invoice->workOrder?->reception?->vehicle),
            'items' => $invoice->items->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => Format::quantity($item->quantity),
                'unit_price' => Format::integer($item->unit_price),
                'discount' => bccomp((string) $item->discount, '0', 2) > 0 ? Format::integer($item->discount) : '',
                'exempt' => $item->tax_rate === TaxRate::EXEMPT ? Format::integer($item->total) : '',
                'taxed_5' => $item->tax_rate === TaxRate::FIVE ? Format::integer($item->total) : '',
                'taxed_10' => $item->tax_rate === TaxRate::TEN ? Format::integer($item->total) : '',
            ]),
            'ivaTotal' => bcadd((string) $invoice->iva_5, (string) $invoice->iva_10, 2),
            // Notas de crédito emitidas fuera del sistema y registradas (P10), con el saldo.
            'creditNotes' => $invoice->creditNotes()->registered()->get(),
            // Factura a la aseguradora desde un reparto (M9): con la referencia del siniestro y la póliza.
            'claim' => $this->insurerClaim($invoice),
        ]);
    }

    /**
     * Recibo de un pago de factura a crédito (los pagos al contado no tienen recibo).
     *
     * @return array{filename: string, content: string}
     */
    public function receipt(Payment $payment): array
    {
        if ($payment->receipt_number === null) {
            throw new PaymentException(
                'El pago no tiene recibo: solo se emiten para facturas a crédito.',
                422,
                'PAYMENT_WITHOUT_RECEIPT'
            );
        }

        $payment->loadMissing(['invoice.client']);

        return $this->render('pdf.receipt', "recibo-{$payment->receipt_number}.pdf", [
            'title' => "Recibo {$payment->receipt_number}",
            'watermark' => $payment->status === PaymentStatus::CANCELLED ? 'ANULADO' : null,
            'payment' => $payment,
            'invoice' => $payment->invoice,
            'balance' => $payment->invoice->balance(),
        ]);
    }

    /**
     * @return array{filename: string, content: string}
     */
    public function delivery(VehicleDelivery $delivery): array
    {
        $delivery->loadMissing([
            'client',
            'vehicle.brand',
            'vehicle.vehicleModel',
            'reception.workOrders.invoices',
            'deliverer',
        ]);

        return $this->render('pdf.delivery', "entrega-{$delivery->delivery_number}.pdf", [
            'title' => "Comprobante de entrega {$delivery->delivery_number}",
            'watermark' => $delivery->status === VehicleDeliveryStatus::CANCELLED ? 'ANULADA' : null,
            'delivery' => $delivery,
            'client' => $this->client($delivery->client),
            'vehicle' => $this->vehicle($delivery->vehicle),
            'workOrders' => $delivery->reception->workOrders
                ->filter(fn ($workOrder) => $workOrder->status === WorkOrderStatus::COMPLETED)
                ->map(fn ($workOrder) => [
                    'code' => $workOrder->code,
                    'invoices' => $workOrder->invoices
                        ->where('status', InvoiceStatus::ISSUED)
                        ->pluck('number')
                        ->implode(', '),
                ])
                ->values(),
            'signature' => $this->signature($delivery->signature_path),
        ]);
    }

    /**
     * @return array{filename: string, content: string}
     */
    public function render(string $view, string $filename, array $data, string $orientation = 'portrait'): array
    {
        $content = Pdf::loadView($view, [
            'company' => config('company'),
            'logo' => $this->brandingService->logoDataUri(),
            'generatedAt' => now(),
            'watermark' => null,
            ...$data,
        ])->setPaper('a4', $orientation)->output();

        return ['filename' => $filename, 'content' => $content];
    }

    private function client(?Client $client): ?array
    {
        return $client ? [
            'name' => $client->display_name,
            'document' => $client->document_number,
            'phone' => $client->phone,
            'email' => $client->email,
        ] : null;
    }

    /**
     * El siniestro de una factura a la aseguradora (la del cliente de la aseguradora desde un reparto); null si no es.
     */
    private function insurerClaim(Invoice $invoice): ?Claim
    {
        $claim = $invoice->allocation_id ? $invoice->allocation?->claim : null;

        return $claim && $claim->insurer()->withTrashed()->value('client_id') === $invoice->client_id ? $claim : null;
    }

    private function vehicle(?Vehicle $vehicle): ?array
    {
        return $vehicle ? [
            'name' => trim("{$vehicle->brand?->name} {$vehicle->vehicleModel?->name}"),
            'plate' => $vehicle->plate,
            'chassis' => $vehicle->chassis,
            'year' => $vehicle->year,
        ] : null;
    }

    /**
     * Firma como data URI: dompdf no lee del disco público por URL.
     */
    private function signature(?string $path): ?string
    {
        if ($path === null || !Storage::disk('public')->exists($path)) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';

        return "data:{$mime};base64," . base64_encode(Storage::disk('public')->get($path));
    }
}

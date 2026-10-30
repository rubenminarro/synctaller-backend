<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelInvoiceRequest;
use App\Http\Requests\IndexInvoiceRequest;
use App\Http\Requests\IssueInvoiceRequest;
use App\Http\Requests\UpdateInvoiceItemRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\ShowInvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\WorkOrder;
use App\Services\DocumentPdfService;
use App\Services\InvoiceService;
use App\Traits\ApiResponse;
use App\Traits\FileResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InvoiceController extends Controller implements HasMiddleware
{
    use ApiResponse, FileResponse;

    public function __construct(
        protected InvoiceService $invoiceService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:invoices.index', only: ['index']),
            new Middleware('permission:invoices.show', only: ['show', 'pdf']),
            new Middleware('permission:invoices.store', only: ['storeFromWorkOrder']),
            new Middleware('permission:invoices.update', only: ['update', 'updateItem']),
            new Middleware('permission:invoices.issue', only: ['issue']),
            new Middleware('permission:invoices.cancel', only: ['cancel']),
            new Middleware('permission:invoices.destroy', only: ['destroy']),
        ];
    }

    public function index(IndexInvoiceRequest $request)
    {
        $search = trim($request->input('search', ''));

        $invoices = Invoice::query()
            ->with('workOrder')
            ->withSum(['payments as paid_total' => fn ($q) => $q->registered()], 'amount')
            ->withSum(['creditNotes as credited_total' => fn ($q) => $q->registered()], 'total')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->client_id))
            ->when($request->filled('work_order_id'), fn ($q) => $q->where('work_order_id', $request->work_order_id))
            ->when($request->filled('search'), function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_tax_id', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->date_to))
            ->orderByDesc('id')
            ->paginate($request->per_page ?? 10);

        return $this->successResponse(
            'Facturas obtenidas correctamente.',
            InvoiceResource::collection($invoices->items()),
            200,
            [
                'pagination' => [
                    'total'       => $invoices->total(),
                    'perPage'     => $invoices->perPage(),
                    'currentPage' => $invoices->currentPage(),
                    'lastPage'    => $invoices->lastPage(),
                ]
            ]
        );
    }

    public function show(Invoice $invoice)
    {
        return $this->successResponse(
            'Factura obtenida correctamente.',
            new ShowInvoiceResource($invoice->load(['items', 'workOrder', 'payments', 'creditNotes.items', 'creditNotes.registrar', 'creditNotes.canceller'])),
            200
        );
    }

    /**
     * Representación imprimible (no es el comprobante fiscal).
     */
    public function pdf(Invoice $invoice, DocumentPdfService $documentPdfService)
    {
        return $this->fileResponse($documentPdfService->invoice($invoice), 'application/pdf');
    }

    public function storeFromWorkOrder(WorkOrder $workOrder)
    {
        $invoice = $this->invoiceService->generate($workOrder, request()->user());

        return $this->successResponse(
            'Factura generada en borrador correctamente.',
            new ShowInvoiceResource($invoice),
            201
        );
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        $invoice = $this->invoiceService->update($invoice, $request->validated());

        return $this->successResponse(
            'Factura actualizada correctamente.',
            new ShowInvoiceResource($invoice),
            200
        );
    }

    public function updateItem(UpdateInvoiceItemRequest $request, Invoice $invoice, InvoiceItem $item)
    {
        $invoice = $this->invoiceService->updateItem($invoice, $item, $request->validated());

        return $this->successResponse(
            'Línea de la factura actualizada correctamente.',
            new ShowInvoiceResource($invoice),
            200
        );
    }

    public function issue(IssueInvoiceRequest $request, Invoice $invoice)
    {
        $invoice = $this->invoiceService->issue(
            $invoice,
            $request->validated('invoice_stamp_id'),
            $request->user()
        );

        return $this->successResponse(
            'Factura emitida correctamente.',
            new ShowInvoiceResource($invoice),
            200
        );
    }

    public function cancel(CancelInvoiceRequest $request, Invoice $invoice)
    {
        $invoice = $this->invoiceService->cancel($invoice, $request->validated('reason'), $request->user());

        return $this->successResponse(
            'Factura anulada correctamente.',
            new ShowInvoiceResource($invoice),
            200
        );
    }

    public function destroy(Invoice $invoice)
    {
        $this->invoiceService->delete($invoice);

        return $this->successResponse(
            'Borrador de factura eliminado correctamente.',
            null,
            200
        );
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelPaymentRequest;
use App\Http\Requests\StoreCreditNoteRequest;
use App\Http\Resources\InvoiceCreditNoteResource;
use App\Models\Invoice;
use App\Models\InvoiceCreditNote;
use App\Services\CreditNoteService;
use App\Traits\ApiResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Notas de crédito emitidas fuera del sistema (P10), en
 * invoices/{invoice}/credit-notes (scopeBindings). Se consultan con el permiso
 * de ver facturas; registrarlas y anularlas es del cajero.
 */
class InvoiceCreditNoteController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        protected CreditNoteService $creditNoteService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:invoices.show', only: ['index', 'show']),
            new Middleware('permission:invoice-credit-notes.store', only: ['store']),
            new Middleware('permission:invoice-credit-notes.cancel', only: ['cancel']),
        ];
    }

    public function index(Invoice $invoice)
    {
        return $this->successResponse(
            'Notas de crédito obtenidas correctamente.',
            InvoiceCreditNoteResource::collection($invoice->creditNotes()->with(['items', 'registrar', 'canceller'])->get()),
            200
        );
    }

    public function store(StoreCreditNoteRequest $request, Invoice $invoice)
    {
        return $this->successResponse(
            'Nota de crédito registrada correctamente.',
            new InvoiceCreditNoteResource($this->creditNoteService->register($invoice, $request->validated(), $request->user())),
            201
        );
    }

    public function show(Invoice $invoice, InvoiceCreditNote $creditNote)
    {
        return $this->successResponse(
            'Nota de crédito obtenida correctamente.',
            new InvoiceCreditNoteResource($this->creditNoteService->load($creditNote)),
            200
        );
    }

    public function cancel(CancelPaymentRequest $request, Invoice $invoice, InvoiceCreditNote $creditNote)
    {
        return $this->successResponse(
            'Nota de crédito anulada correctamente.',
            new InvoiceCreditNoteResource($this->creditNoteService->cancel($creditNote, $request->validated()['reason'], $request->user())),
            200
        );
    }
}

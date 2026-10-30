<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Factura de un responsable desde el reparto confirmado (M9): el cliente al que
 * se factura (la aseguradora o el dueño). Sus porciones se verifican en
 * InvoiceService::generateForAllocation.
 */
class StoreAllocationInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'Indique el cliente al que se factura.',
            'client_id.integer' => 'El cliente no es válido.',
            'client_id.exists' => 'El cliente seleccionado no existe.',
        ];
    }
}

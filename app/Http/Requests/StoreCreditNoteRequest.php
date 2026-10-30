<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro de una nota de crédito emitida fuera del sistema (P10): número y
 * timbrado tal como se emitió, fecha, motivo y lo acreditado por línea de la
 * factura (con IVA, en guaraníes). Los topes por línea y por saldo se verifican
 * en CreditNoteService.
 */
class StoreCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/.]+$/'],
            'stamp_number' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/.]+$/'],
            'issue_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.invoice_item_id' => ['required', 'integer', 'distinct'],
            'items.*.amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'number.required' => 'El número de la nota de crédito es obligatorio.',
            'number.max' => 'El número no debe superar los 30 caracteres.',
            'number.regex' => 'El número solo admite letras, números, guiones, barras y puntos.',
            'stamp_number.max' => 'El timbrado no debe superar los 30 caracteres.',
            'stamp_number.regex' => 'El timbrado solo admite letras, números, guiones, barras y puntos.',
            'issue_date.required' => 'La fecha de la nota de crédito es obligatoria.',
            'issue_date.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'issue_date.before_or_equal' => 'La fecha de la nota de crédito no puede ser futura.',
            'reason.required' => 'El motivo es obligatorio.',
            'reason.min' => 'El motivo debe tener al menos 3 caracteres.',
            'reason.max' => 'El motivo no debe superar los 1000 caracteres.',
            'items.required' => 'Indique al menos una línea de la factura a acreditar.',
            'items.array' => 'Las líneas deben enviarse como una lista.',
            'items.min' => 'Indique al menos una línea de la factura a acreditar.',
            'items.*.invoice_item_id.required' => 'Cada línea debe indicar la línea de la factura.',
            'items.*.invoice_item_id.integer' => 'La línea de la factura no es válida.',
            'items.*.invoice_item_id.distinct' => 'Una línea de la factura no puede repetirse.',
            'items.*.amount.required' => 'Cada línea debe indicar el importe acreditado.',
            'items.*.amount.integer' => 'El importe acreditado debe ser un número entero de guaraníes.',
            'items.*.amount.min' => 'El importe acreditado debe ser mayor a cero.',
            'items.*.amount.max' => 'El importe acreditado es demasiado alto.',
        ];
    }
}

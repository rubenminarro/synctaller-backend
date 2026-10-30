<?php

namespace App\Http\Requests;

use App\Enums\InvoiceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class IndexInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'client_id' => ['nullable', 'integer'],
            'work_order_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'status' => [
                Enum::class => 'El estado debe ser draft, issued o cancelled.',
            ],
            'client_id' => [
                'integer' => 'El ID del cliente debe ser un número entero.',
            ],
            'work_order_id' => [
                'integer' => 'El ID de la orden de trabajo debe ser un número entero.',
            ],
            'date_from' => [
                'date_format' => 'La fecha desde debe tener el formato AAAA-MM-DD.',
            ],
            'date_to' => [
                'date_format' => 'La fecha hasta debe tener el formato AAAA-MM-DD.',
                'after_or_equal' => 'La fecha hasta debe ser igual o posterior a la fecha desde.',
            ],
            'per_page' => [
                'integer' => 'La cantidad por página debe ser un número entero.',
                'min' => 'La cantidad por página debe ser al menos 1.',
                'max' => 'La cantidad por página no puede superar 100.',
            ],
        ];
    }
}

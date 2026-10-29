<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'budget_id' => [
                'required',
                'integer',
                Rule::exists('budgets', 'id'),
            ],
            'mechanic_id' => [
                'required',
                'integer',
                Rule::exists('mechanics', 'id'),
            ],
            // Siniestro (M7): excepción para crear la OT sin la autorización de la aseguradora.
            'internal_exception_id' => [
                'nullable',
                'integer',
                Rule::exists('internal_exceptions', 'id'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'budget_id' => [
                'required' => 'El presupuesto es obligatorio.',
                'integer' => 'El ID del presupuesto debe ser un número entero.',
                'exists' => 'El presupuesto seleccionado no existe.'
            ],
            'mechanic_id' => [
                'required' => 'El mecánico es obligatorio.',
                'integer' => 'El ID del mecánico debe ser un número entero.',
                'exists' => 'El mecánico seleccionado no existe.'
            ],
            'internal_exception_id' => [
                'integer' => 'El ID de la excepción debe ser un número entero.',
                'exists' => 'La excepción seleccionada no existe.',
            ],
        ];
    }
}
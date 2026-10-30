<?php

namespace App\Http\Requests;

use App\Enums\DeductibleBillingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Edición de la aseguradora: plazo de pago, modalidad del deducible por defecto
 * (M10) y notas. Razón social, RUC, email y teléfono se editan en su cliente.
 */
class UpdateInsurerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'deductible_billing_mode' => ['sometimes', 'nullable', Rule::prohibitedIf(fn () => !config('insurer.deductible_net_mode')), Rule::enum(DeductibleBillingMode::class)],
            'payment_term_days' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:365',
            ],
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
                'regex:/^[\pL\pN\s.,;:()\-#@!?%]*$/u',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'deductible_billing_mode' => [
                'prohibited' => 'La modalidad neta del deducible no está habilitada en este sistema.',
                Enum::class => 'La modalidad del deducible debe ser net_of_deductible.',
            ],
            'payment_term_days' => [
                'integer' => 'El plazo de pago debe ser un número entero de días.',
                'min' => 'El plazo de pago no puede ser negativo.',
                'max' => 'El plazo de pago no puede superar los 365 días.',
            ],
            'notes' => [
                'string' => 'Las notas deben ser un texto válido.',
                'max' => 'Las notas no deben superar los 1000 caracteres.',
                'regex' => 'Las notas contienen caracteres no permitidos.',
            ],
        ];
    }
}

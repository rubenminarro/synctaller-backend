<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\BudgetItemType;
use Illuminate\Validation\Rules\Enum;

class UpdateBudgetItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => [
                'sometimes',
                Rule::enum(BudgetItemType::class)
            ],
            'part_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('parts', 'id')->whereNull('deleted_at'),
            ],
            'service_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('services', 'id')->whereNull('deleted_at'),
            ],
            'description' => [
                'sometimes',
                'string',
                'max:255',
                // Mismos caracteres que el alta (incluye %).
                'regex:/^[\pL\pN\s.,;:()\-#@!?%]*$/u'
            ],
            'quantity' => [
                'sometimes',
                'numeric',
                'min:0.01',
                // Columna decimal(10,2).
                'max:99999999.99',
            ],
            'unit_price' => [
                'sometimes',
                'numeric',
                'min:0',
                // Columna decimal(14,2) (BudgetService::MAX_AMOUNT).
                'max:999999999999.99',
            ],
            'estimated_hours' => [
                'sometimes',
                'nullable',
                'numeric',
                'decimal:0,2',
                'min:0',
                'max:999999.99',
            ],
            // Opcional en el alta: también se puede vaciar al editar.
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
                'regex:/^[\pL\pN\s.,;:()\-#@!?]*$/u'
            ],
        ];
    }

    public function messages(): array
    {        
        return [
            'type' => [
                Enum::class => 'El tipo debe ser un valor válido.',
            ],
            'part_id' => [
                'integer' => 'El ID del repuesto debe ser un número entero.',
                'exists' => 'El repuesto seleccionado no existe.',
            ],
            'service_id' => [
                'integer' => 'El ID del servicio debe ser un número entero.',
                'exists' => 'El servicio seleccionado no existe.',
            ],
            'description' => [
                'string' => 'La descripción debe ser una cadena de texto.',
                'max' => 'La descripción no puede exceder los 255 caracteres.',
                'regex' => 'La descripción solo puede contener letras, números, espacios y los siguientes caracteres: . , ; : ( ) - # @ ! ? %',
            ],
            'quantity' => [
                'numeric' => 'La cantidad debe ser un número.',
                'min' => 'La cantidad debe ser al menos 0.01.',
                'max' => 'La cantidad supera el máximo permitido.',
            ],
            'unit_price' => [
                'numeric' => 'El precio unitario debe ser un número.',
                'min' => 'El precio unitario no puede ser negativo.',
                'max' => 'El precio unitario supera el máximo permitido.',
            ],
            'estimated_hours' => [
                'numeric' => 'Las horas estimadas deben ser un número.',
                'decimal' => 'Las horas estimadas admiten como máximo 2 decimales.',
                'min' => 'Las horas estimadas no pueden ser negativas.',
                'max' => 'Las horas estimadas superan el máximo permitido.',
            ],
            'notes' => [
                'string' => 'Las notas deben ser una cadena de texto.',
                'max' => 'Las notas no pueden exceder los 500 caracteres.',
                'regex' => 'Las notas solo pueden contener letras, números, espacios y los siguientes caracteres: . , ; : ( ) - # @ ! ?',
            ],
        ];
    }
}

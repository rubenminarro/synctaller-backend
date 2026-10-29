<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\BudgetItemResponsibility;
use App\Enums\BudgetItemType;
use Illuminate\Validation\Rules\Enum;

class StoreBudgetItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => [
                'required',
                Rule::enum(BudgetItemType::class)
            ],
            // Quién paga la línea; sin enviarlo, el dueño. 'insurer' exige un siniestro abierto (BudgetService).
            'responsibility' => [
                'sometimes',
                Rule::enum(BudgetItemResponsibility::class),
            ],
            'part_id' => [
                Rule::prohibitedIf(fn () => $this->input('type') !== BudgetItemType::PART->value),
                'nullable',
                'integer',
                Rule::exists('parts', 'id')->whereNull('deleted_at'),
            ],
            'service_id' => [
                Rule::prohibitedIf(fn () => $this->input('type') !== BudgetItemType::SERVICE->value),
                'nullable',
                'integer',
                Rule::exists('services', 'id')->whereNull('deleted_at'),
            ],
            'description' => [
                'required',
                'string',
                'max:255',
                'regex:/^[\pL\pN\s.,;:()\-#@!?%]*$/u'
            ],
            'quantity' => [
                'required',
                'numeric',
                'min:0.01',
                // Columna decimal(10,2).
                'max:99999999.99',
            ],
            'unit_price' => [
                // Un servicio del catálogo toma su precio estándar si no se informa.
                Rule::requiredIf(fn () => !(
                    $this->input('type') === BudgetItemType::SERVICE->value && $this->filled('service_id')
                )),
                'nullable',
                'numeric',
                'min:0',
                // Columna decimal(14,2) (BudgetService::MAX_AMOUNT).
                'max:999999999999.99',
            ],
            'estimated_hours' => [
                Rule::prohibitedIf(fn () => $this->input('type') === BudgetItemType::PART->value),
                'nullable',
                'numeric',
                'decimal:0,2',
                'min:0',
                'max:999999.99',
            ],
            'notes' => [
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
                'required' => 'El tipo de ítem es obligatorio.',
                Enum::class => 'El tipo de ítem debe ser un valor válido.',
            ],
            'responsibility' => [
                Enum::class => 'El responsable debe ser owner (dueño) o insurer (aseguradora).',
            ],
            'part_id' => [
                'prohibited' => 'Solo los ítems de tipo repuesto pueden vincularse a un repuesto del catálogo.',
                'integer' => 'El ID del repuesto debe ser un número entero.',
                'exists' => 'El repuesto seleccionado no existe.',
            ],
            'service_id' => [
                'prohibited' => 'Solo los ítems de tipo servicio pueden vincularse a un servicio del catálogo.',
                'integer' => 'El ID del servicio debe ser un número entero.',
                'exists' => 'El servicio seleccionado no existe.',
            ],
            'description' => [
                'required' => 'La descripción es obligatoria.',
                'string' => 'La descripción debe ser una cadena de texto.',
                'max' => 'La descripción no puede exceder los 255 caracteres.',
                'regex' => 'La descripción solo puede contener letras, números, espacios y los siguientes caracteres: . , ; : ( ) - # @ ! ? %',
            ],
            'quantity' => [
                'required' => 'La cantidad es obligatoria.',
                'numeric' => 'La cantidad debe ser un número.',
                'min' => 'La cantidad debe ser al menos 0.01.',
                'max' => 'La cantidad supera el máximo permitido.',
            ],
            'unit_price' => [
                'required' => 'El precio unitario es obligatorio.',
                'numeric' => 'El precio unitario debe ser un número.',
                'min' => 'El precio unitario no puede ser negativo.',
                'max' => 'El precio unitario supera el máximo permitido.',
            ],
            'estimated_hours' => [
                'prohibited' => 'Los repuestos no llevan tiempo estimado.',
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

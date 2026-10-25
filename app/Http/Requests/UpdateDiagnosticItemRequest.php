<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use App\Enums\Severity;
use App\Enums\DiagnosticItemStatus;

class UpdateDiagnosticItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // El título y el ítem del catálogo quedan fijos (como el repuesto de una línea de compra).
            'title' => [
                'prohibited',
            ],
            'diagnostic_item_template_id' => [
                'prohibited',
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^[\pL\pN\s.,;:()\-#@!?%]*$/u'
            ],
            // severity y status no admiten null en la base (columnas NOT NULL con default).
            'severity' => [
                'sometimes',
                'required',
                Rule::enum(Severity::class),
            ],
            'status' => [
                'sometimes',
                'required',
                Rule::enum(DiagnosticItemStatus::class)
            ],
            'requires_repair' => [
                'sometimes',
                'boolean'
            ],
            'requires_replacement' => [
                'sometimes',
                'boolean'
            ],
            'estimated_cost' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0'
            ],
            'estimated_time' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0'
            ],
            'recommendation' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^[\pL\pN\s.,;:()\-#@!?]*$/u'
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'title' => [
                'prohibited' => 'El título sale del ítem del catálogo y no se cambia. Elimine el ítem y agregue otro.',
            ],
            'diagnostic_item_template_id' => [
                'prohibited' => 'El ítem del catálogo no se cambia. Elimine el ítem y agregue otro.',
            ],
            'description' => [
                'string' => 'La descripción debe ser una cadena de texto.',
                'regex' => 'La descripción solo pueden contener letras, números, espacios y los siguientes caracteres: . , ; : ( ) - # @ ! ? %',
            ],
            'severity' => [
                'required' => 'La severidad no puede quedar vacía.',
                Enum::class => 'La prioridad debe ser uno de los siguientes: low, medium, high, critical.',
            ],
            'status' => [
                'required' => 'El estado no puede quedar vacío.',
                Enum::class => 'El estado debe ser uno de los siguientes: pending, ok, observation, repair_required, replace_required, not_applicable.',
            ],
            'requires_repair' => [
                'boolean' => 'El campo requiere_repair debe ser verdadero o falso.',
            ],
            'requires_replacement' => [
                'boolean' => 'El campo requires_replacement debe ser verdadero o falso.',
            ],
            'estimated_cost' => [
                'numeric' => 'El costo estimado debe ser un número.',
                'min' => 'El costo estimado no puede ser negativo.',
            ],
            'estimated_time' => [
                'integer' => 'El tiempo estimado debe ser un número entero.',
                'min' => 'El tiempo estimado no puede ser negativo.',
            ],
            'recommendation' => [
                'string' => 'La recomendación debe ser una cadena de texto.',
                'regex' => 'La recomendación solo puede contener letras, números, espacios y caracteres de puntuación comunes.',
            ],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use App\Enums\Severity;
use App\Enums\DiagnosticItemStatus;

class StoreDiagnosticItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'diagnostic_id' => [
                'integer',
                'required',
                Rule::exists('diagnostics', 'id')
            ],
            // El ítem se elige siempre del catálogo (como el repuesto en una línea de compra): el título sale de él y
            // los campos que no se envían se completan con los suyos (DiagnosticItemTemplateService).
            'diagnostic_item_template_id' => [
                'required',
                'integer',
                Rule::exists('diagnostic_item_templates', 'id')->whereNull('deleted_at'),
            ],
            'title' => [
                'prohibited',
            ],
            'description' => [
                'nullable',
                'string',
                'regex:/^[\pL\pN\s.,;:()\-#@!?%]*$/u'
            ],
            'severity' => [
                'nullable',
                Rule::enum(Severity::class),
            ],
            'status' => [
                'nullable',
                Rule::enum(DiagnosticItemStatus::class)
            ],
            'requires_repair' => [
                'nullable',
                'boolean'
            ],
            'requires_replacement' => [
                'nullable',
                'boolean'
            ],
            'estimated_cost' => [
                'nullable',
                'numeric',
                'min:0'
            ],
            'estimated_time' => [
                'nullable',
                'integer',
                'min:0'
            ],
            'recommendation' => [
                'nullable',
                'string',
                'regex:/^[\pL\pN\s.,;:()\-#@!?]*$/u'
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'diagnostic_id' => [
                'integer' => 'El ID del diagnóstico debe ser un número entero.',
                'required' => 'El ID del diagnóstico es obligatorio.',
                'exists' => 'El diagnóstico especificado no existe.',
            ],
            'diagnostic_item_template_id' => [
                'required' => 'Elija el ítem del catálogo de diagnóstico.',
                'integer' => 'El ID del ítem del catálogo debe ser un número entero.',
                'exists' => 'El ítem del catálogo seleccionado no existe.',
            ],
            'title' => [
                'prohibited' => 'El título sale del ítem del catálogo: no se envía.',
            ],
            'description' => [
                'string' => 'La descripción debe ser una cadena de texto.',
                'regex' => 'La descripción solo pueden contener letras, números, espacios y los siguientes caracteres: . , ; : ( ) - # @ ! ? %',
            ],
            'severity' => [
                Enum::class => 'La prioridad debe ser uno de los siguientes: low, medium, high, critical.',
            ],
            'status' => [
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

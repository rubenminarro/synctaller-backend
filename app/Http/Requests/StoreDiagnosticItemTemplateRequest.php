<?php

namespace App\Http\Requests;

use App\Enums\Severity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Mismas reglas de texto que el ítem del diagnóstico (StoreDiagnosticItemRequest): sus valores se copian al ítem.
 */
class StoreDiagnosticItemTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_category_id' => [
                'nullable',
                'integer',
                Rule::exists('service_categories', 'id')->whereNull('deleted_at'),
            ],
            'title' => [
                'required',
                'string',
                'max:255',
                'regex:/^[\pL\pN\s]*$/u',
                Rule::unique('diagnostic_item_templates', 'title')->whereNull('deleted_at'),
            ],
            'description' => [
                'nullable',
                'string',
                'regex:/^[\pL\pN\s.,;:()\-#@!?%]*$/u',
            ],
            'severity' => [
                'required',
                Rule::enum(Severity::class),
            ],
            'requires_repair' => [
                'sometimes',
                'boolean',
            ],
            'requires_replacement' => [
                'sometimes',
                'boolean',
            ],
            'estimated_time' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'recommendation' => [
                'nullable',
                'string',
                'regex:/^[\pL\pN\s.,;:()\-#@!?]*$/u',
            ],
        ];
    }

    public function messages(): array
    {
        return self::validationMessages();
    }

    public static function validationMessages(): array
    {
        return [
            'service_category_id' => [
                'integer' => 'El ID de la categoría debe ser un número entero.',
                'exists' => 'La categoría seleccionada no existe.',
            ],
            'title' => [
                'required' => 'El título es obligatorio.',
                'string' => 'El título debe ser una cadena de texto.',
                'max' => 'El título no puede exceder los 255 caracteres.',
                'regex' => 'El título solo puede contener letras, números y espacios.',
                'unique' => 'Ya existe un ítem de diagnóstico en el catálogo con ese título.',
            ],
            'description' => [
                'string' => 'La descripción debe ser una cadena de texto.',
                'regex' => 'La descripción solo pueden contener letras, números, espacios y los siguientes caracteres: . , ; : ( ) - # @ ! ? %',
            ],
            'severity' => [
                'required' => 'La severidad es obligatoria.',
                Enum::class => 'La severidad debe ser uno de los siguientes: low, medium, high, critical.',
            ],
            'requires_repair' => [
                'boolean' => 'El campo requires_repair debe ser verdadero o falso.',
            ],
            'requires_replacement' => [
                'boolean' => 'El campo requires_replacement debe ser verdadero o falso.',
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

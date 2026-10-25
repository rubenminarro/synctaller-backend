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
            // Ítem del catálogo: los campos que no se envían se completan con los suyos (DiagnosticItemTemplateService).
            'diagnostic_item_template_id' => [
                'nullable',
                'integer',
                Rule::exists('diagnostic_item_templates', 'id')->whereNull('deleted_at'),
                'prohibited_if_accepted:save_as_template',
            ],
            // Ítem libre que además se guarda en el catálogo (requiere diagnostic-item-templates.store).
            'save_as_template' => [
                'sometimes',
                'boolean',
            ],
            'title' => [
                'required_without:diagnostic_item_template_id',
                'string',
                'max:255',
                'regex:/^[\pL\pN\s]*$/u',
                Rule::when(
                    $this->boolean('save_as_template'),
                    [Rule::unique('diagnostic_item_templates', 'title')->whereNull('deleted_at')]
                ),
            ],
            'description' => [
                'nullable',
                'string',
                'regex:/^[\pL\pN\s.,;:()\-#@!?%]*$/u'
            ],
            'severity' => [
                'required_without:diagnostic_item_template_id',
                Rule::enum(Severity::class),
            ],
            'status' => [
                'required_without:diagnostic_item_template_id',
                Rule::enum(DiagnosticItemStatus::class)
            ],
            'requires_repair' => [
                'required_without:diagnostic_item_template_id',
                'boolean'
            ],
            'requires_replacement' => [
                'required_without:diagnostic_item_template_id',
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
                'integer' => 'El ID del ítem del catálogo debe ser un número entero.',
                'exists' => 'El ítem del catálogo seleccionado no existe.',
                'prohibited_if_accepted' => 'Un ítem elegido del catálogo no se vuelve a guardar en el catálogo.',
            ],
            'save_as_template' => [
                'boolean' => 'El campo save_as_template debe ser verdadero o falso.',
            ],
            'title' => [
                'required_without' => 'El título es obligatorio.',
                'unique' => 'Ya existe un ítem de diagnóstico en el catálogo con ese título.',
                'string' => 'El título debe ser una cadena de texto.',
                'max' => 'El título no puede exceder los 255 caracteres.',
                'regex' => 'El título solo puede contener letras, números y espacios.',
            ],
            'description' => [
                'string' => 'La descripción debe ser una cadena de texto.',
                'regex' => 'La descripción solo pueden contener letras, números, espacios y los siguientes caracteres: . , ; : ( ) - # @ ! ? %',
            ],
            'severity' => [
                'required_without' => 'La severidad es obligatoria.',
                Enum::class => 'La prioridad debe ser uno de los siguientes: low, medium, high, critical.',
            ],
            'status' => [
                'required_without' => 'El estado es obligatorio.',
                Enum::class => 'El estado debe ser uno de los siguientes: pending, ok, observation, repair_required, replace_required, not_applicable.',
            ],
            'requires_repair' => [
                'required_without' => 'El campo requires_repair es obligatorio.',
                'boolean' => 'El campo requiere_repair debe ser verdadero o falso.',
            ],
            'requires_replacement' => [
                'required_without' => 'El campo requires_replacement es obligatorio.',
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

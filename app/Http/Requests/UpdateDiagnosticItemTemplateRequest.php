<?php

namespace App\Http\Requests;

use App\Enums\Severity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDiagnosticItemTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_category_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('service_categories', 'id')->whereNull('deleted_at'),
            ],
            'title' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'regex:/^[\pL\pN\s]*$/u',
                Rule::unique('diagnostic_item_templates', 'title')
                    ->whereNull('deleted_at')
                    ->ignore($this->route('diagnostic_item_template')),
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^[\pL\pN\s.,;:()\-#@!?%]*$/u',
            ],
            'severity' => [
                'sometimes',
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
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],
            'recommendation' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^[\pL\pN\s.,;:()\-#@!?]*$/u',
            ],
        ];
    }

    public function messages(): array
    {
        return StoreDiagnosticItemTemplateRequest::validationMessages();
    }
}

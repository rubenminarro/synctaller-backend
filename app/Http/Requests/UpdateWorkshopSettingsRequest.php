<?php

namespace App\Http\Requests;

use App\Enums\TaxRate;
use App\Support\WorkshopSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateWorkshopSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $email = config('validation.email_dns') ? 'email:rfc,dns' : 'email:rfc';

        return [
            'company_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'company_legal_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'company_tax_id' => ['sometimes', 'nullable', 'string', 'max:30'],
            'company_activity' => ['sometimes', 'nullable', 'string', 'max:150'],
            'company_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'company_email' => ['sometimes', 'nullable', 'string', $email, 'max:150'],
            'default_tax_rate' => ['sometimes', 'nullable', 'integer', Rule::enum(TaxRate::class)],
            'mail_from_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'mail_from_address' => ['sometimes', 'nullable', 'string', $email, 'max:150'],
            'reminders_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'invoice_due_soon_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'invoice_overdue_repeat_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (array_intersect_key($this->all(), WorkshopSettings::FIELDS) === []) {
                    $validator->errors()->add('settings', 'Debe enviar al menos un dato para actualizar.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'string' => 'El dato debe ser un texto.',
            'integer' => 'El dato debe ser un número entero.',
            'email' => 'El correo debe tener el formato correcto.',
            'max' => 'El dato supera el máximo permitido (:max).',
            'min' => 'El dato debe ser como mínimo :min.',
            'default_tax_rate.enum' => 'La tasa de IVA debe ser 0, 5 o 10.',
            'reminders_at.date_format' => 'La hora debe tener el formato HH:MM.',
        ];
    }
}

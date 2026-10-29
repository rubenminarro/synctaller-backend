<?php

namespace App\Http\Requests;

use App\Enums\ClientKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        // Sin kind, persona: el alta de siempre sigue igual.
        $isCompany = $this->input('kind') === ClientKind::COMPANY->value;

        return [
            'kind' => [
                'sometimes',
                Rule::enum(ClientKind::class),
            ],
            'business_name' => [
                Rule::requiredIf($isCompany),
                Rule::prohibitedIf(!$isCompany),
                'nullable',
                'string',
                'min:2',
                'max:150',
                // Mismos caracteres que el nombre de la factura (UpdateInvoiceRequest).
                'regex:/^[\pL\pN\s.,\-&()\']+$/u',
            ],
            'address' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[\pL\pN\s.,#\-\/°º&()\']+$/u',
            ],
            'document_number' => [
                'required', 
                'string', 
                'min:5', 
                'max:20',
                Rule::unique('clients', 'document_number'),
                'regex:/^[a-zA-Z0-9-]+$/'
            ],
            'first_name' => [
                Rule::requiredIf(!$isCompany),
                Rule::prohibitedIf($isCompany),
                'nullable',
                'string',
                'min:2',
                'max:100',
                'regex:/^[a-zA-Z\sñÑáéíóúÁÉÍÓÚ]+$/u'
            ],
            'last_name' => [
                Rule::requiredIf(!$isCompany),
                Rule::prohibitedIf($isCompany),
                'nullable',
                'string',
                'min:2', 
                'max:100',
                'regex:/^[a-zA-Z\sñÑáéíóúÁÉÍÓÚ]+$/u'
            ],
            'email' => [
                'required', 
                config('validation.email_dns') ? 'email:rfc,dns' : 'email:rfc',
                'max:50', 
                'lowercase',
                Rule::unique('clients', 'email')
            ],
            'phone' => [
                'required',
                'regex:/^\+?[1-9]\d{7,14}$/'
            ],
            'notifications_enabled' => [
                'sometimes',
                'boolean',
            ],
            'notes' => [
                'nullable', 
                'string', 
                'max:1000',
                'regex:/^[\pL\pN\s.,;:()\-#@!?]*$/u',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'kind' => [
                Enum::class => 'El tipo de cliente debe ser person (persona) o company (empresa).',
            ],
            'business_name' => [
                'required' => 'La razón social es obligatoria para una empresa.',
                'prohibited' => 'La razón social solo corresponde a una empresa.',
                'string' => 'La razón social debe tener el formato correcto.',
                'min' => 'La razón social debe tener al menos 2 caracteres.',
                'max' => 'La razón social no debe tener más de 150 caracteres.',
                'regex' => 'La razón social contiene caracteres no permitidos.',
            ],
            'address' => [
                'string' => 'La dirección debe tener el formato correcto.',
                'max' => 'La dirección no debe tener más de 255 caracteres.',
                'regex' => 'La dirección contiene caracteres no permitidos.',
            ],
            'document_number' => [
                'required' => 'El número de documento es obligatorio.',
                'string' => 'El número de documento debe tener el formato correcto.',
                'min'      => 'El número de documento debe tener al menos 5 caracteres.',
                'max'      => 'El número de documento no debe tener más de 20 caracteres.',
                'unique' => 'Este número de documento ya está en uso.',
                'regex'  => 'El número de documento solo puede contener letras, números y guiones.',
            ],
            'first_name' => [
                'required' => 'El nombre es obligatorio.',
                'prohibited' => 'Una empresa no lleva nombre: use la razón social.',
                'string'   => 'El nombre debe tener el formato correcto.',
                'min'      => 'El nombre debe tener al menos 2 caracteres.',
                'max'      => 'El nombre no debe tener más de 100 caracteres.',
                'regex'    => 'El nombre solo puede contener letras y espacios.',
            ],
            'last_name' => [
                'required' => 'El apellido es obligatorio.',
                'prohibited' => 'Una empresa no lleva apellido: use la razón social.',
                'string'   => 'El apellido debe tener el formato correcto.',
                'min'      => 'El apellido debe tener al menos 2 caracteres.',
                'max'      => 'El apellido no debe tener más de 100 caracteres.',
                'regex'    => 'El apellido solo puede contener letras y espacios.',
            ],
            'email' => [
                'required'  => 'El correo es obligatorio.',
                'email'     => 'El correo debe tener el formato correcto.',
                'max'       => 'El correo no debe tener más de 50 caracteres.',
                'lowercase' => 'El correo debe estar en minúsculas.',
                'unique'    => 'Este correo ya está en uso.',
            ],
            'phone' => [
                'required' => 'El teléfono es obligatorio.',
                'regex'    => 'El teléfono debe tener un formato válido (opcionalmente con un + al inicio, seguido de 8 a 15 dígitos).',
            ],
            'notifications_enabled' => [
                'boolean' => 'El campo de avisos debe ser verdadero o falso.',
            ],
            'notes' => [
                'string' => 'Las notas deben tener el formato correcto.',
                'max'    => 'Las notas no deben tener más de 1000 caracteres.',
                'regex'  => 'Las notas solo pueden contener letras, números, espacios y los siguientes caracteres: . , ; : ( ) - # @ ! ?',
            ],
        ];
    }
}
<?php

namespace App\Http\Requests;

use App\Enums\InsurerContactRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Contacto de una aseguradora. En el alta (POST) nombre y rol son obligatorios;
 * al editar (PATCH) todo es opcional y los opcionales se pueden vaciar con null.
 */
class InsurerContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [
                $presence,
                'string',
                'min:2',
                'max:150',
                'regex:/^[\pL\s.\'\-]+$/u',
            ],
            'role' => [
                $presence,
                Rule::enum(InsurerContactRole::class),
            ],
            'phone' => [
                'sometimes',
                'nullable',
                'regex:/^\+?[1-9]\d{7,14}$/',
            ],
            'email' => [
                'sometimes',
                'nullable',
                config('validation.email_dns') ? 'email:rfc,dns' : 'email:rfc',
                'max:100',
            ],
            'is_primary' => [
                'sometimes',
                'boolean',
            ],
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
                'regex:/^[\pL\pN\s.,;:()\-#@!?%]*$/u',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name' => [
                'required' => 'El nombre del contacto es obligatorio.',
                'string' => 'El nombre debe ser un texto válido.',
                'min' => 'El nombre debe tener al menos 2 caracteres.',
                'max' => 'El nombre no debe superar los 150 caracteres.',
                'regex' => 'El nombre solo puede contener letras, espacios, puntos, apóstrofos y guiones.',
            ],
            'role' => [
                'required' => 'El rol del contacto es obligatorio.',
                Enum::class => 'El rol debe ser claims, billing, adjuster_coordination u other.',
            ],
            'phone' => [
                'regex' => 'El teléfono debe tener un formato válido (opcionalmente con un + al inicio, seguido de 8 a 15 dígitos).',
            ],
            'email' => [
                'email' => 'El email debe tener un formato válido.',
                'max' => 'El email no debe superar los 100 caracteres.',
            ],
            'is_primary' => [
                'boolean' => 'El campo principal debe ser verdadero o falso.',
            ],
            'notes' => [
                'string' => 'Las notas deben ser un texto válido.',
                'max' => 'Las notas no deben superar los 500 caracteres.',
                'regex' => 'Las notas contienen caracteres no permitidos.',
            ],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Baja de un documento de siniestro: el motivo es obligatorio.
 */
class DestroyClaimDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason' => [
                'required' => 'El motivo de la baja es obligatorio.',
                'string' => 'El motivo debe ser un texto válido.',
                'min' => 'El motivo debe tener al menos 3 caracteres.',
                'max' => 'El motivo no debe superar los 1000 caracteres.',
            ],
        ];
    }
}

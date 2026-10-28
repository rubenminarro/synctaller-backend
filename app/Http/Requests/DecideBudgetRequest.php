<?php

namespace App\Http\Requests;

use App\Enums\ClientDecisionChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Respuesta del cliente a un presupuesto (aprobar o rechazar). Todo es
 * opcional: sin body se registra igual quién y cuándo. Mismas reglas que la
 * respuesta a un repuesto extra (DecideWorkOrderPartRequest).
 */
class DecideBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => [
                'nullable',
                Rule::enum(ClientDecisionChannel::class),
            ],
            'contact' => [
                'nullable',
                'string',
                'max:150',
            ],
            'notes' => [
                'nullable',
                'string',
                'min:3',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'channel' => [
                'enum' => 'El medio seleccionado no es válido.',
            ],
            'contact' => [
                'string' => 'El contacto debe ser un texto válido.',
                'max' => 'El contacto no debe superar los 150 caracteres.',
            ],
            'notes' => [
                'string' => 'Las notas deben ser un texto válido.',
                'min' => 'Las notas deben tener al menos 3 caracteres.',
                'max' => 'Las notas no deben superar los 500 caracteres.',
            ],
        ];
    }
}

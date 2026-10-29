<?php

namespace App\Http\Requests;

/**
 * Re-aceptación del dueño (M7): las reglas de la respuesta al presupuesto, con
 * el medio obligatorio (es la evidencia de la decisión B).
 */
class ReacceptBudgetRequest extends DecideBudgetRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['channel'][0] = 'required';

        return $rules;
    }

    public function messages(): array
    {
        $messages = parent::messages();
        $messages['channel']['required'] = 'El medio por el que respondió el cliente es obligatorio.';

        return $messages;
    }
}

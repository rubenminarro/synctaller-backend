<?php

namespace App\Http\Requests;

use App\Enums\InternalExceptionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Excepción interna (M7). El tipo define el sujeto: start_without_authorization
 * va sobre un presupuesto y workshop_absorption sobre una OT, con importe (sin
 * IVA). Las condiciones del sujeto se verifican en InternalExceptionService.
 */
class StoreInternalExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(InternalExceptionType::class)],
            'subject_id' => ['required', 'integer', 'min:1'],
            'amount' => [
                'prohibited_unless:type,' . InternalExceptionType::WORKSHOP_ABSORPTION->value,
                'required_if:type,' . InternalExceptionType::WORKSHOP_ABSORPTION->value,
                'nullable',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:999999999999.99',
            ],
            'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/^[\pL\pN\s.,;:()\-#@!?%\/°º&\'"]+$/u'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'El tipo de excepción es obligatorio.',
            'type.' . Enum::class => 'El tipo debe ser start_without_authorization o workshop_absorption.',
            'subject_id.required' => 'Indique el presupuesto o la orden de trabajo de la excepción.',
            'subject_id.integer' => 'El documento de la excepción no es válido.',
            'subject_id.min' => 'El documento de la excepción no es válido.',
            'amount.prohibited_unless' => 'Solo la absorción del taller lleva importe.',
            'amount.required_if' => 'La absorción del taller exige el importe.',
            'amount.numeric' => 'El importe debe ser un número.',
            'amount.decimal' => 'El importe admite como máximo 2 decimales.',
            'amount.gt' => 'El importe debe ser mayor a cero.',
            'amount.max' => 'El importe es demasiado alto.',
            'reason.required' => 'El motivo es obligatorio.',
            'reason.string' => 'El motivo debe ser un texto válido.',
            'reason.min' => 'El motivo debe tener al menos 10 caracteres.',
            'reason.max' => 'El motivo no debe superar los 1000 caracteres.',
            'reason.regex' => 'El motivo contiene caracteres no permitidos.',
        ];
    }
}

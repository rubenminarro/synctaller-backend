<?php

namespace App\Http\Requests;

use App\Enums\AllocationPortionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Edición del reparto en borrador (M8): `lines` reemplaza todas las porciones; el
 * deducible es el importe informado (capa B). Las reglas R1–R7 se verifican al
 * confirmar (AllocationService).
 */
class UpdateWorkOrderAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.source_type' => ['required', Rule::in(['work_order_item', 'work_order_part'])],
            'lines.*.source_id' => ['required', 'integer', 'min:1'],
            'lines.*.portion_type' => ['required', Rule::enum(AllocationPortionType::class)],
            'lines.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
            'lines.*.internal_exception_id' => ['nullable', 'integer', 'min:1'],
            'deductible_amount' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000', 'regex:/^[\pL\pN\s.,;:()\-#@!?%\/°º&\'"]*$/u'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.array' => 'Las porciones deben enviarse como una lista.',
            'lines.min' => 'El reparto necesita al menos una porción.',
            'lines.*.source_type.required' => 'Cada porción debe indicar el tipo de concepto.',
            'lines.*.source_type.in' => 'El concepto debe ser work_order_item o work_order_part.',
            'lines.*.source_id.required' => 'Cada porción debe indicar el concepto.',
            'lines.*.source_id.integer' => 'El concepto no es válido.',
            'lines.*.source_id.min' => 'El concepto no es válido.',
            'lines.*.portion_type.required' => 'Cada porción debe indicar su tipo.',
            'lines.*.portion_type.' . Enum::class => 'El tipo debe ser insurer_covered, owner_responsibility, owner_uncovered o workshop_absorbed.',
            'lines.*.amount.required' => 'Cada porción debe indicar su importe.',
            'lines.*.amount.numeric' => 'El importe debe ser un número.',
            'lines.*.amount.decimal' => 'El importe admite como máximo 2 decimales.',
            'lines.*.amount.gt' => 'El importe debe ser mayor a cero.',
            'lines.*.amount.max' => 'El importe es demasiado alto.',
            'lines.*.internal_exception_id.integer' => 'La excepción no es válida.',
            'lines.*.internal_exception_id.min' => 'La excepción no es válida.',
            'deductible_amount.required' => 'El deducible no puede quedar vacío.',
            'deductible_amount.numeric' => 'El deducible debe ser un número.',
            'deductible_amount.decimal' => 'El deducible admite como máximo 2 decimales.',
            'deductible_amount.min' => 'El deducible no puede ser negativo.',
            'deductible_amount.max' => 'El deducible es demasiado alto.',
            'notes.string' => 'Las notas deben ser un texto válido.',
            'notes.max' => 'Las notas no deben superar los 1000 caracteres.',
            'notes.regex' => 'Las notas contienen caracteres no permitidos.',
        ];
    }
}

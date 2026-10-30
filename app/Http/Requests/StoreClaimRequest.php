<?php

namespace App\Http\Requests;

use App\Enums\ClaimType;
use App\Enums\DeductibleBillingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Alta de siniestro. En uno propio, sin datos del asegurado se copian los del
 * cliente de la recepción (ClaimService); en uno de tercero el nombre del
 * asegurado es obligatorio.
 */
class StoreClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reception_id' => ['required', 'integer', Rule::exists('receptions', 'id')->whereNull('deleted_at')],
            'insurer_id' => ['required', 'integer', Rule::exists('insurers', 'id')->whereNull('deleted_at')],
            'claim_type' => ['required', Rule::enum(ClaimType::class)],
            'loss_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'insured_name' => [
                Rule::requiredIf($this->input('claim_type') === ClaimType::THIRD_PARTY->value),
                'nullable',
                ...self::NAME,
            ],
            ...$this->detailRules('nullable'),
        ];
    }

    /** Nombre de persona o razón social (mismos caracteres que el nombre de la factura). */
    protected const NAME = ['string', 'min:2', 'max:150', 'regex:/^[\pL\pN\s.,\-&()\']+$/u'];

    /**
     * Campos opcionales, comunes al alta y la edición.
     */
    protected function detailRules(string ...$presence): array
    {
        $email = config('validation.email_dns') ? 'email:rfc,dns' : 'email:rfc';
        $text = ['string', 'max:2000', 'regex:/^[\pL\pN\s.,;:()\-#@!?%\/°º&\'"]*$/u'];

        return [
            'claim_number' => [...$presence, 'string', 'max:50', 'regex:/^[A-Za-z0-9\-\/.]+$/'],
            'policy_number' => [...$presence, 'string', 'max:50', 'regex:/^[A-Za-z0-9\-\/.]+$/'],
            'loss_description' => [...$presence, ...$text],
            'insured_document' => [...$presence, 'string', 'max:30', 'regex:/^[A-Za-z0-9\-]+$/'],
            'insured_phone' => [...$presence, 'regex:/^\+?[1-9]\d{7,14}$/'],
            'insured_email' => [...$presence, $email, 'max:100'],
            'adjuster_name' => [...$presence, ...self::NAME],
            'adjuster_phone' => [...$presence, 'regex:/^\+?[1-9]\d{7,14}$/'],
            'adjuster_email' => [...$presence, $email, 'max:100'],
            'inspection_scheduled_at' => [...$presence, 'date'],
            'inspected_at' => [...$presence, 'date', 'before_or_equal:now'],
            'deductible_amount' => [...$presence, 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            // M10: sin enviarla, el alta copia la de la aseguradora.
            'deductible_billing_mode' => [...$presence, Rule::prohibitedIf(fn () => !config('insurer.deductible_net_mode')), Rule::enum(DeductibleBillingMode::class)],
            'deductible_includes_tax' => [...$presence, 'boolean'],
            'coverage_notes' => [...$presence, ...$text],
        ];
    }

    public function messages(): array
    {
        return [
            'reception_id.required' => 'La recepción es obligatoria.',
            'reception_id.exists' => 'La recepción seleccionada no existe.',
            'insurer_id.required' => 'La aseguradora es obligatoria.',
            'insurer_id.exists' => 'La aseguradora seleccionada no existe.',
            'claim_type.required' => 'El tipo de siniestro es obligatorio.',
            'claim_type.' . Enum::class => 'El tipo de siniestro debe ser own (propio) o third_party (de tercero).',
            'claim_type.prohibited' => 'El tipo de siniestro no se cambia: cancele el siniestro y registre otro.',
            'loss_date.required' => 'La fecha del siniestro es obligatoria.',
            'loss_date.date_format' => 'La fecha del siniestro debe tener el formato AAAA-MM-DD.',
            'loss_date.before_or_equal' => 'La fecha del siniestro no puede ser futura.',
            'insured_name.required' => 'El nombre del asegurado es obligatorio en un siniestro de tercero.',
            '*.regex' => 'El campo contiene caracteres no permitidos.',
            '*.max' => 'El campo supera el largo máximo permitido.',
            '*.min' => 'El campo es demasiado corto.',
            '*.email' => 'El email debe tener un formato válido.',
            'insured_phone.regex' => 'El teléfono debe tener un formato válido (opcionalmente con un + al inicio, seguido de 8 a 15 dígitos).',
            'adjuster_phone.regex' => 'El teléfono debe tener un formato válido (opcionalmente con un + al inicio, seguido de 8 a 15 dígitos).',
            '*.date' => 'La fecha no es válida.',
            'inspected_at.before_or_equal' => 'La fecha de inspección no puede ser futura.',
            'deductible_amount.numeric' => 'El deducible debe ser un número.',
            'deductible_amount.decimal' => 'El deducible admite como máximo 2 decimales.',
            'deductible_amount.min' => 'El deducible no puede ser negativo.',
            'deductible_amount.max' => 'El deducible supera el máximo permitido.',
            'deductible_billing_mode.prohibited' => 'La modalidad neta del deducible no está habilitada en este sistema.',
            'deductible_billing_mode.' . Enum::class => 'La modalidad del deducible debe ser net_of_deductible.',
            'deductible_includes_tax.boolean' => 'Indique si el deducible incluye el IVA (true o false).',
            'reception_id.prohibited' => 'La recepción de un siniestro no se cambia.',
            'insurer_id.prohibited' => 'La aseguradora de un siniestro no se cambia: cancele el siniestro y registre otro.',
        ];
    }
}

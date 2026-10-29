<?php

namespace App\Http\Requests;

use App\Enums\ClientDecisionChannel;
use App\Enums\InsurerAuthorizationDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Autorización de la aseguradora (M6): una línea por cada ítem a su cargo. En una
 * línea parcial se informa el importe autorizado; amounts_include_tax dice si se
 * cargó con IVA (se guarda sin IVA, como el presupuesto). Topes y coincidencia
 * con el presupuesto en InsurerAuthorizationService.
 */
class StoreInsurerAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $text = ['string', 'regex:/^[\pL\pN\s.,;:()\-#@!?%\/°º&\'"]*$/u'];

        return [
            'amounts_include_tax' => ['required', 'boolean'],
            'decided_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'channel' => ['required', Rule::enum(ClientDecisionChannel::class)],
            'authorization_number' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-\/.]+$/'],
            'adjuster_name' => ['nullable', 'string', 'min:2', 'max:150', 'regex:/^[\pL\pN\s.,\-&()\']+$/u'],
            'notes' => ['nullable', ...$text, 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.budget_item_id' => ['required', 'integer', 'distinct'],
            'items.*.decision' => ['required', Rule::enum(InsurerAuthorizationDecision::class)],
            'items.*.authorized_total' => [
                'exclude_unless:items.*.decision,' . InsurerAuthorizationDecision::PARTIAL->value,
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:999999999999.99',
            ],
            'items.*.authorized_quantity' => [
                'exclude_unless:items.*.decision,' . InsurerAuthorizationDecision::PARTIAL->value,
                'nullable',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:99999999.99',
            ],
            'items.*.notes' => ['nullable', ...$text, 'max:500'],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'amounts_include_tax.required' => 'Indique si los importes autorizados incluyen IVA.',
            'amounts_include_tax.boolean' => 'El indicador de IVA debe ser verdadero o falso.',
            'decided_at.required' => 'La fecha de la autorización es obligatoria.',
            'decided_at.date_format' => 'La fecha de la autorización debe tener el formato AAAA-MM-DD.',
            'decided_at.before_or_equal' => 'La fecha de la autorización no puede ser futura.',
            'channel.required' => 'El medio por el que llegó la autorización es obligatorio.',
            'channel.' . Enum::class => 'El medio seleccionado no es válido.',
            'items.required' => 'Informe la decisión de la aseguradora sobre cada línea a su cargo.',
            'items.array' => 'Las líneas deben enviarse como una lista.',
            'items.min' => 'Informe la decisión de la aseguradora sobre cada línea a su cargo.',
            'items.*.budget_item_id.required' => 'Cada línea debe indicar el ítem del presupuesto.',
            'items.*.budget_item_id.integer' => 'El ítem del presupuesto no es válido.',
            'items.*.budget_item_id.distinct' => 'Un ítem del presupuesto no puede repetirse.',
            'items.*.decision.required' => 'Cada línea debe indicar la decisión de la aseguradora.',
            'items.*.decision.' . Enum::class => 'La decisión debe ser authorized, partial o not_covered.',
            'items.*.authorized_total.required' => 'En una línea parcial el importe autorizado es obligatorio.',
            'items.*.authorized_total.numeric' => 'El importe autorizado debe ser un número.',
            'items.*.authorized_total.decimal' => 'El importe autorizado admite como máximo 2 decimales.',
            'items.*.authorized_total.gt' => 'El importe autorizado debe ser mayor que cero (si no cubre nada, use not_covered).',
            'items.*.authorized_total.max' => 'El importe autorizado supera el máximo permitido.',
            'items.*.authorized_quantity.numeric' => 'La cantidad autorizada debe ser un número.',
            'items.*.authorized_quantity.decimal' => 'La cantidad autorizada admite como máximo 2 decimales.',
            'items.*.authorized_quantity.gt' => 'La cantidad autorizada debe ser mayor que cero.',
            'items.*.authorized_quantity.max' => 'La cantidad autorizada supera el máximo permitido.',
            'document_ids.array' => 'Los documentos deben enviarse como una lista.',
            'document_ids.*.integer' => 'El documento no es válido.',
            'document_ids.*.distinct' => 'Un documento no puede repetirse.',
            '*.regex' => 'El campo contiene caracteres no permitidos.',
            '*.max' => 'El campo supera el largo máximo permitido.',
            '*.min' => 'El campo es demasiado corto.',
            'items.*.notes.regex' => 'Las notas contienen caracteres no permitidos.',
            'items.*.notes.max' => 'Las notas no deben superar los 500 caracteres.',
        ];
    }
}

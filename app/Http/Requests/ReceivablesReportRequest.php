<?php

namespace App\Http\Requests;

use App\Enums\InvoiceRecipientType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Cuentas por cobrar (M11): a hoy, sin período. Filtros por tipo de destinatario
 * y por cliente (con el cliente, también el detalle de sus facturas).
 */
class ReceivablesReportRequest extends ReportRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'recipient_type' => ['nullable', Rule::enum(InvoiceRecipientType::class)],
            'client_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'recipient_type.' . Enum::class => 'El tipo de destinatario debe ser insurer o customer.',
            'client_id.integer' => 'El cliente no es válido.',
        ];
    }

    public function recipientType(): ?InvoiceRecipientType
    {
        return $this->filled('recipient_type') ? InvoiceRecipientType::from($this->recipient_type) : null;
    }
}

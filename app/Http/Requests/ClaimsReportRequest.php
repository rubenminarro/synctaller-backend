<?php

namespace App\Http\Requests;

use App\Enums\ClaimStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Reporte de siniestros (M11): período por fecha del siniestro (por defecto, el
 * mes en curso), con filtros por aseguradora y estado.
 */
class ClaimsReportRequest extends ReportRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'insurer_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ClaimStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'insurer_id.integer' => 'La aseguradora no es válida.',
            'status.' . Enum::class => 'El estado debe ser open, closed o cancelled.',
        ];
    }

    public function status(): ?ClaimStatus
    {
        return $this->filled('status') ? ClaimStatus::from($this->status) : null;
    }
}

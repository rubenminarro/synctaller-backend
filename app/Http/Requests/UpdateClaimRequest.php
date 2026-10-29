<?php

namespace App\Http\Requests;

/**
 * Edición de un siniestro abierto. Recepción, aseguradora y tipo no cambian
 * (para eso se cancela y se registra otro). Los opcionales se vacían con null,
 * incluido el deducible (vuelve a "desconocido").
 */
class UpdateClaimRequest extends StoreClaimRequest
{
    public function rules(): array
    {
        return [
            'reception_id' => ['prohibited'],
            'insurer_id' => ['prohibited'],
            'claim_type' => ['prohibited'],
            'loss_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'insured_name' => ['sometimes', ...self::NAME],
            ...$this->detailRules('sometimes', 'nullable'),
        ];
    }
}

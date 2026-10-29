<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShowBudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'total' => $this->total,
            'notes' => $this->notes,
            'approved_at' => $this->approved_at,
            'reception_id' => $this->reception_id,
            'reception' => new ReceptionResource(
                $this->whenLoaded('reception')
            ),
            'created_by' => $this->created_by,
            'creator' => new UserResource(
                $this->whenLoaded('creator')
            ),
            'items' => BudgetItemResource::collection(
                $this->whenLoaded('items')
            ),
            'client_acceptances' => BudgetClientAcceptanceResource::collection(
                $this->whenLoaded('clientAcceptances')
            ),
            // Autorización vigente de la aseguradora (M6): solo con insurer-authorizations.show.
            'insurer_authorization' => $this->when(
                $request->user()?->can('insurer-authorizations.show') ?? false,
                fn () => $this->currentInsurerAuthorization ? [
                    'id' => $this->currentInsurerAuthorization->id,
                    'version' => $this->currentInsurerAuthorization->version,
                    'result' => [
                        'value' => $this->currentInsurerAuthorization->result->value,
                        'label' => $this->currentInsurerAuthorization->result->label(),
                    ],
                    'authorization_number' => $this->currentInsurerAuthorization->authorization_number,
                    'decided_at' => $this->currentInsurerAuthorization->decided_at?->format('Y-m-d'),
                    'authorized_total' => $this->currentInsurerAuthorization->authorized_total,
                ] : null
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
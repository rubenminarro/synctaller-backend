<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadatos del documento. Nunca la ruta interna ni una URL: se descarga por
 * GET claims/{claim}/documents/{document}/download.
 */
class ClaimDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'claim_id' => $this->claim_id,
            // Evidencia de una autorización de la aseguradora (M6): no se da de baja.
            'insurer_authorization_id' => $this->insurer_authorization_id,
            'type' => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
            ],
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'notes' => $this->notes,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader
                ? ['id' => $this->uploader->id, 'name' => $this->uploader->name]
                : null),
            'created_at' => $this->created_at,
        ];
    }
}

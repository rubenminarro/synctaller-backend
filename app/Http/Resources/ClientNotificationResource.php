<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client_name' => $this->whenLoaded('client', fn () => $this->client?->display_name ?? ''),
            'type' => [
                'value' => $this->type?->value,
                'label' => $this->type?->label(),
            ],
            'channel' => [
                'value' => $this->channel?->value,
                'label' => $this->channel?->label(),
            ],
            'subject' => [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
            ],
            'recipient' => $this->recipient,
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->label(),
            ],
            'error' => $this->error,
            'sent_at' => $this->sent_at,
            'resent_from_id' => $this->resent_from_id,
            'created_at' => $this->created_at,
        ];
    }
}

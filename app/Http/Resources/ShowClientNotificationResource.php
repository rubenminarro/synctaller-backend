<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShowClientNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'full_name' => $this->client->display_name,
                'email' => $this->client->email,
                'phone' => $this->client->phone,
                'notifications_enabled' => $this->client->notifications_enabled,
            ] : null),
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
                'code' => $this->whenLoaded('subject', fn () => $this->subject?->code ?? $this->subject?->number),
            ],
            'recipient' => $this->recipient,
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->label(),
            ],
            'error' => $this->error,
            'attempts' => $this->attempts,
            'sent_at' => $this->sent_at,
            'resent_from_id' => $this->resent_from_id,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

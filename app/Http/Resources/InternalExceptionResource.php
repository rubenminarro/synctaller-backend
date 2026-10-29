<?php

namespace App\Http\Resources;

use App\Models\InternalException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Excepción interna (M7): sujeto (presupuesto u OT), importe en la absorción y la
 * operación que la consumió, si ya se usó.
 */
class InternalExceptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var InternalException $exception */
        $exception = $this->resource;

        return [
            'id' => $this->id,
            'type' => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
            ],
            'subject' => self::document($this->subject_type, $this->subject_id, $exception->subject),
            'amount' => $this->amount,
            'reason' => $this->reason,
            'granted_by' => $this->grantor === null ? null : [
                'id' => $this->grantor->id,
                'name' => $this->grantor->name,
            ],
            'granted_at' => $this->granted_at,
            'consumed_at' => $this->consumed_at,
            'consumed_by' => $this->consumed_by_type === null
                ? null
                : self::document($this->consumed_by_type, $this->consumed_by_id, $exception->consumedBy),
            'created_at' => $this->created_at,
        ];
    }

    private static function document(string $type, int $id, ?Model $model): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'code' => $model?->code,
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\AllocationPortionType;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Porción de un concepto vendido en un reparto (sin IVA). Solo cambia mientras su
 * reparto está en borrador, y entonces se reemplaza: no se edita.
 */
class WorkOrderAllocationLine extends Model
{
    use Auditable;

    protected $fillable = [
        'work_order_allocation_id',
        'portion_type',
        'source_type',
        'source_id',
        'budget_item_id',
        'client_id',
        'insurer_authorization_item_id',
        'internal_exception_id',
        'amount',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'portion_type' => AllocationPortionType::class,
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => WorkOrderAllocation::immutable());

        $onlyDraft = function (self $line) {
            if (!WorkOrderAllocation::whereKey($line->work_order_allocation_id)->first()?->isDraft()) {
                WorkOrderAllocation::immutable();
            }
        };

        static::creating($onlyDraft);
        static::deleting($onlyDraft);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(WorkOrderAllocation::class, 'work_order_allocation_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function authorizationItem(): BelongsTo
    {
        return $this->belongsTo(InsurerAuthorizationItem::class, 'insurer_authorization_item_id');
    }

    public function internalException(): BelongsTo
    {
        return $this->belongsTo(InternalException::class);
    }
}

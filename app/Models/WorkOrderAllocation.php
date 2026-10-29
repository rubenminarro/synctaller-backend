<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use App\Exceptions\AllocationException;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versión del reparto económico de una OT de siniestro (M8). El borrador se edita
 * y se elimina; confirmada, solo se registra su sustitución por otra versión.
 */
class WorkOrderAllocation extends Model
{
    use Auditable;

    /** Lo único que cambia en una versión confirmada. */
    private const MUTABLE_WHEN_CONFIRMED = ['status', 'superseded_by_id', 'superseded_at', 'updated_at'];

    protected $fillable = [
        'work_order_id',
        'work_order_closing_id',
        'claim_id',
        'version',
        'status',
        'sale_total',
        'deductible_amount',
        'notes',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'superseded_by_id',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AllocationStatus::class,
            'sale_total' => 'decimal:2',
            'deductible_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $allocation) {
            $original = $allocation->getOriginal('status');

            if ($original === AllocationStatus::DRAFT) {
                return;
            }

            $changesContent = array_diff(array_keys($allocation->getDirty()), self::MUTABLE_WHEN_CONFIRMED) !== [];

            if ($original === AllocationStatus::SUPERSEDED || $changesContent) {
                self::immutable();
            }
        });

        static::deleting(function (self $allocation) {
            if ($allocation->status !== AllocationStatus::DRAFT) {
                self::immutable();
            }
        });
    }

    public static function immutable(): never
    {
        throw new AllocationException(
            'Un reparto confirmado no se modifica: genere una nueva versión.',
            409,
            'ALLOCATION_IMMUTABLE'
        );
    }

    public function isDraft(): bool
    {
        return $this->status === AllocationStatus::DRAFT;
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    public function closing(): BelongsTo
    {
        return $this->belongsTo(WorkOrderClosing::class, 'work_order_closing_id');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderAllocationLine::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by')->withTrashed();
    }
}

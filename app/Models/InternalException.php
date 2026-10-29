<?php

namespace App\Models;

use App\Enums\InternalExceptionType;
use App\Exceptions\InternalExceptionException;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Excepción interna del flujo de siniestros (M7, decisión C), sobre un
 * presupuesto o una OT. Inmutable: lo único que cambia, una sola vez, es el
 * registro de la operación que la consumió.
 */
class InternalException extends Model
{
    use Auditable;

    /** Lo único que cambia después de otorgarla. */
    private const MUTABLE = ['consumed_at', 'consumed_by_type', 'consumed_by_id', 'updated_at'];

    protected $fillable = [
        'type',
        'subject_type',
        'subject_id',
        'amount',
        'reason',
        'granted_by',
        'granted_at',
        'consumed_at',
        'consumed_by_type',
        'consumed_by_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => InternalExceptionType::class,
            'amount' => 'decimal:2',
            'granted_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $exception) {
            $changesContent = array_diff(array_keys($exception->getDirty()), self::MUTABLE) !== [];

            if ($changesContent || $exception->getOriginal('consumed_at') !== null) {
                self::immutable();
            }
        });

        static::deleting(fn () => self::immutable());
    }

    public static function immutable(): never
    {
        throw new InternalExceptionException(
            'Una excepción interna otorgada no se modifica ni se elimina.',
            409,
            'INTERNAL_EXCEPTION_IMMUTABLE'
        );
    }

    public function scopeUnconsumed(Builder $query): Builder
    {
        return $query->whereNull('consumed_at');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function consumedBy(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by')->withTrashed();
    }
}

<?php

namespace App\Models;

use App\Enums\ClientDecisionChannel;
use App\Enums\InsurerAuthorizationResult;
use App\Enums\InsurerAuthorizationStatus;
use App\Exceptions\InsurerAuthorizationException;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Autorización de la aseguradora sobre un presupuesto (M6), versionada. Inmutable:
 * solo se registra su sustitución por otra versión o su invalidación.
 */
class InsurerAuthorization extends Model
{
    use Auditable;

    /** Lo único que cambia después de registrarla. */
    private const MUTABLE = ['status', 'superseded_by_id', 'superseded_at', 'invalidated_at', 'invalidation_reason', 'updated_at'];

    protected $fillable = [
        'budget_id',
        'claim_id',
        'version',
        'status',
        'result',
        'authorization_number',
        'decided_at',
        'channel',
        'adjuster_name',
        'authorized_total',
        'notes',
        'registered_by',
        'superseded_by_id',
        'superseded_at',
        'invalidated_at',
        'invalidation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => InsurerAuthorizationStatus::class,
            'result' => InsurerAuthorizationResult::class,
            'channel' => ClientDecisionChannel::class,
            'decided_at' => 'date',
            'authorized_total' => 'decimal:2',
            'superseded_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $authorization) {
            $changesContent = array_diff(array_keys($authorization->getDirty()), self::MUTABLE) !== [];
            $wasClosed = $authorization->getOriginal('status') !== InsurerAuthorizationStatus::CURRENT;

            if ($changesContent || $wasClosed) {
                self::immutable();
            }
        });

        static::deleting(fn () => self::immutable());
    }

    public static function immutable(): never
    {
        throw new InsurerAuthorizationException(
            'Una autorización registrada no se modifica: registre una nueva versión.',
            409,
            'INSURER_AUTHORIZATION_IMMUTABLE'
        );
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class)->withTrashed();
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InsurerAuthorizationItem::class)->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ClaimDocument::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by')->withTrashed();
    }
}

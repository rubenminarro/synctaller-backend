<?php

namespace App\Models;

use App\Enums\CreditNoteStatus;
use App\Exceptions\InvoiceException;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nota de crédito emitida fuera del sistema y registrada sobre una factura
 * emitida (P10). No se edita ni se elimina: un registro mal cargado se anula.
 */
class InvoiceCreditNote extends Model
{
    use Auditable;

    private const CANCELLATION_FIELDS = ['status', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'updated_at'];

    protected $fillable = [
        'invoice_id',
        'number',
        'stamp_number',
        'issue_date',
        'reason',
        'status',
        'total',
        'total_exempt',
        'total_5',
        'total_10',
        'iva_5',
        'iva_10',
        'registered_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => CreditNoteStatus::class,
            'issue_date' => 'date',
            'total' => 'decimal:2',
            'total_exempt' => 'decimal:2',
            'total_5' => 'decimal:2',
            'total_10' => 'decimal:2',
            'iva_5' => 'decimal:2',
            'iva_10' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $creditNote) {
            $original = CreditNoteStatus::from($creditNote->getRawOriginal('status'));
            $changed = array_diff(array_keys($creditNote->getDirty()), self::CANCELLATION_FIELDS);

            if ($original === CreditNoteStatus::CANCELLED || $changed !== []) {
                self::immutable();
            }
        });

        static::deleting(fn () => self::immutable());
    }

    public static function immutable(): never
    {
        throw new InvoiceException(
            'Una nota de crédito registrada no se modifica ni se elimina; solo puede anularse.',
            409,
            'CREDIT_NOTE_IMMUTABLE'
        );
    }

    public function scopeRegistered(Builder $query): Builder
    {
        return $query->where('status', CreditNoteStatus::REGISTERED);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceCreditNoteItem::class)->orderBy('id');
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by')->withTrashed();
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }
}

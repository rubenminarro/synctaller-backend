<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Enums\CreditNoteStatus;
use App\Enums\InvoiceCondition;
use App\Enums\InvoicePaymentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvoiceException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Factura de una OT completada. Precios con IVA incluido, importes en guaraníes.
 */
class Invoice extends Model
{
    use Auditable;

    /**
     * Campos que pueden cambiar una vez emitida (solo la anulación).
     */
    private const CANCELLATION_FIELDS = ['status', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'updated_at'];

    protected $fillable = [
        'work_order_id',
        'allocation_id',
        'client_id',
        'status',
        'invoice_stamp_id',
        'stamp_number',
        'stamp_valid_until',
        'number',
        'customer_name',
        'customer_tax_id',
        'customer_address',
        'condition',
        'due_date',
        'total_exempt',
        'total_5',
        'total_10',
        'iva_5',
        'iva_10',
        'discount_total',
        'total',
        'created_by',
        'issue_date',
        'issued_at',
        'issued_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'condition' => InvoiceCondition::class,
            'stamp_valid_until' => 'date',
            'due_date' => 'date',
            'issue_date' => 'date',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_exempt' => 'decimal:2',
            'total_5' => 'decimal:2',
            'total_10' => 'decimal:2',
            'iva_5' => 'decimal:2',
            'iva_10' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * Una factura emitida es un documento fiscal: solo puede anularse.
     */
    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice) {
            $original = InvoiceStatus::from($invoice->getRawOriginal('status'));

            if ($original === InvoiceStatus::DRAFT) {
                return;
            }

            $changed = array_diff(array_keys($invoice->getDirty()), self::CANCELLATION_FIELDS);

            if ($original === InvoiceStatus::CANCELLED || $changed !== []) {
                throw new InvoiceException(
                    'La factura emitida no puede modificarse; solo puede anularse.',
                    409,
                    'INVOICE_NOT_EDITABLE'
                );
            }
        });

        static::deleting(function (Invoice $invoice) {
            if ($invoice->status !== InvoiceStatus::DRAFT) {
                throw new InvoiceException(
                    'Solo puede eliminarse una factura en borrador.',
                    409,
                    'INVOICE_NOT_EDITABLE'
                );
            }
        });
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereIn('status', InvoiceStatus::currentStatuses());
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    /**
     * Versión del reparto que factura (M9); nula en el flujo particular.
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(WorkOrderAllocation::class, 'allocation_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function stamp(): BelongsTo
    {
        return $this->belongsTo(InvoiceStamp::class, 'invoice_stamp_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /**
     * Total cobrado (pagos no anulados). Usa paid_total si vino de withSum
     * o la relación si está cargada.
     */
    public function paidTotal(): string
    {
        if (array_key_exists('paid_total', $this->attributes)) {
            return bcadd((string) ($this->attributes['paid_total'] ?? '0'), '0', 2);
        }

        $payments = $this->relationLoaded('payments')
            ? $this->payments->where('status', PaymentStatus::REGISTERED)
            : $this->payments()->registered()->get();

        return $payments->reduce(fn (string $sum, Payment $p) => bcadd($sum, $p->amount, 2), '0.00');
    }

    /**
     * Notas de crédito registradas sobre la factura (P10), también las anuladas.
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(InvoiceCreditNote::class)->orderBy('id');
    }

    /**
     * Total acreditado (notas de crédito vigentes). Usa credited_total si vino de
     * withSum o la relación si está cargada.
     */
    public function creditedTotal(): string
    {
        if (array_key_exists('credited_total', $this->attributes)) {
            return bcadd((string) ($this->attributes['credited_total'] ?? '0'), '0', 2);
        }

        $creditNotes = $this->relationLoaded('creditNotes')
            ? $this->creditNotes->where('status', CreditNoteStatus::REGISTERED)
            : $this->creditNotes()->registered()->get();

        return $creditNotes->reduce(fn (string $sum, InvoiceCreditNote $note) => bcadd($sum, $note->total, 2), '0.00');
    }

    /**
     * Saldo: total menos lo cobrado y lo acreditado (P10).
     */
    public function balance(): string
    {
        return bcsub(bcsub((string) $this->total, $this->paidTotal(), 2), $this->creditedTotal(), 2);
    }

    /**
     * Estado de cobro: solo aplica a facturas emitidas. Saldada solo con notas de
     * crédito, sin pagos: acreditada (P10).
     */
    public function paymentStatus(): ?InvoicePaymentStatus
    {
        if ($this->status !== InvoiceStatus::ISSUED) {
            return null;
        }

        return match (true) {
            bccomp($this->balance(), '0', 2) <= 0 && bccomp($this->paidTotal(), '0', 2) === 0 && bccomp($this->creditedTotal(), '0', 2) > 0 => InvoicePaymentStatus::CREDITED,
            bccomp($this->balance(), '0', 2) <= 0 => InvoicePaymentStatus::PAID,
            bccomp($this->paidTotal(), '0', 2) > 0 => InvoicePaymentStatus::PARTIAL,
            default => InvoicePaymentStatus::UNPAID,
        };
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}

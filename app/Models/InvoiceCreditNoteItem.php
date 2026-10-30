<?php

namespace App\Models;

use App\Enums\TaxRate;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de una nota de crédito registrada: lo que acredita de una línea de la
 * factura, con IVA (guaraníes) y su base sin IVA. Inmutable.
 */
class InvoiceCreditNoteItem extends Model
{
    use Auditable;

    protected $fillable = [
        'invoice_credit_note_id',
        'invoice_item_id',
        'description',
        'tax_rate',
        'amount',
        'base_amount',
    ];

    protected function casts(): array
    {
        return [
            'tax_rate' => TaxRate::class,
            'amount' => 'decimal:2',
            'base_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => InvoiceCreditNote::immutable());
        static::deleting(fn () => InvoiceCreditNote::immutable());
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(InvoiceCreditNote::class, 'invoice_credit_note_id');
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }
}

<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Enums\TaxRate;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Línea de factura. base_unit_price / base_total: sin IVA (origen en la OT).
 * unit_price / total: con IVA incluido, en guaraníes.
 */
class InvoiceItem extends Model
{
    use Auditable;

    protected $fillable = [
        'invoice_id',
        'source_type',
        'source_id',
        'allocation_line_id',
        'description',
        'quantity',
        'base_unit_price',
        'base_total',
        'tax_rate',
        'unit_price',
        'discount',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'base_unit_price' => 'decimal:2',
            'base_total' => 'decimal:2',
            'tax_rate' => TaxRate::class,
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * Recalcula precio con IVA y total a partir del precio sin IVA,
     * la tasa y el descuento. Redondeo a la moneda (config invoice.decimals).
     */
    public function recalculate(): void
    {
        $decimals = (int) config('invoice.decimals', 0);
        $rate = (string) $this->tax_rate->value;

        $this->unit_price = Decimal::round(
            bcdiv(bcmul($this->base_unit_price, bcadd('100', $rate, 0), 8), '100', 8),
            $decimals
        );

        $gross = Decimal::round(bcmul($this->quantity, $this->unit_price, 8), $decimals);

        $this->total = bcsub($gross, Decimal::of($this->discount ?? '0', $decimals), $decimals);
    }

    /**
     * Importe bruto (cantidad × precio con IVA), antes del descuento.
     */
    public function grossAmount(): string
    {
        return Decimal::round(bcmul($this->quantity, $this->unit_price, 8), (int) config('invoice.decimals', 0));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}

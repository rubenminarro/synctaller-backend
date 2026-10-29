<?php

namespace App\Models;

use App\Enums\InsurerAuthorizationDecision;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de una autorización: lo que la aseguradora autoriza de un ítem del
 * presupuesto (importes sin IVA). Inmutable.
 */
class InsurerAuthorizationItem extends Model
{
    use Auditable;

    protected $fillable = [
        'insurer_authorization_id',
        'budget_item_id',
        'description',
        'budgeted_quantity',
        'budgeted_total',
        'decision',
        'authorized_quantity',
        'authorized_unit_price',
        'authorized_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'decision' => InsurerAuthorizationDecision::class,
            'budgeted_quantity' => 'decimal:2',
            'budgeted_total' => 'decimal:2',
            'authorized_quantity' => 'decimal:2',
            'authorized_unit_price' => 'decimal:2',
            'authorized_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => InsurerAuthorization::immutable());
        static::deleting(fn () => InsurerAuthorization::immutable());
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(InsurerAuthorization::class, 'insurer_authorization_id');
    }

    public function budgetItem(): BelongsTo
    {
        return $this->belongsTo(BudgetItem::class);
    }
}

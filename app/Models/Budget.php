<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\BudgetStatus;
use App\Enums\InsurerAuthorizationStatus;
use App\Support\Decimal;
use App\Traits\Auditable;

class Budget extends Model
{

    use Auditable, SoftDeletes;

    protected $fillable = [
        'reception_id',
        'created_by',
        'code',
        'status',
        'subtotal',
        'tax',
        'total',
        'notes',
        'approved_at',
    ];

    protected $casts = [
        'status' => BudgetStatus::class,
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'approved_at' => 'datetime',
    ];
    
    /**
     * Tasa de IVA con la que se calculó: sale de los importes guardados, no de la
     * configuración, que pudo cambiar después (el presupuesto no guarda la tasa).
     */
    public function taxRate(): int
    {
        if (bccomp((string) $this->subtotal, '0', 2) !== 1) {
            return (int) config('invoice.default_tax_rate', 10);
        }

        return (int) Decimal::round(bcdiv(bcmul((string) $this->tax, '100', 4), (string) $this->subtotal, 4), 0);
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workOrder(): HasOne
    {
        return $this->hasOne(WorkOrder::class);
    }

    /**
     * Autorizaciones de la aseguradora, todas las versiones.
     */
    public function insurerAuthorizations(): HasMany
    {
        return $this->hasMany(InsurerAuthorization::class)->orderBy('version');
    }

    /**
     * Autorización vigente (como máximo una).
     */
    public function currentInsurerAuthorization(): HasOne
    {
        return $this->hasOne(InsurerAuthorization::class)->where('status', InsurerAuthorizationStatus::CURRENT->value);
    }

    /**
     * Respuestas del cliente, en orden (reabrir no borra las anteriores).
     */
    public function clientAcceptances(): HasMany
    {
        return $this->hasMany(BudgetClientAcceptance::class)->orderBy('decided_at')->orderBy('id');
    }

}

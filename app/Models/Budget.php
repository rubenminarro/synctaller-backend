<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\BudgetStatus;
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
     * Respuestas del cliente, en orden (reabrir no borra las anteriores).
     */
    public function clientAcceptances(): HasMany
    {
        return $this->hasMany(BudgetClientAcceptance::class)->orderBy('decided_at')->orderBy('id');
    }

}

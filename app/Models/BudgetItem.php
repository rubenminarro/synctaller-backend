<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\BudgetItemResponsibility;
use App\Enums\BudgetItemType;


class BudgetItem extends Model
{
    use Auditable;

    
    protected $fillable = [
        'budget_id',
        'type',
        'responsibility',
        'part_id',
        'service_id',
        'description',
        'quantity',
        'unit_price',
        'total',
        'estimated_hours',
        'notes',
    ];

    protected $attributes = [
        'responsibility' => 'owner',
    ];

    protected $casts = [
        'type' => BudgetItemType::class,
        'responsibility' => BudgetItemResponsibility::class,
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'estimated_hours' => 'decimal:2',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class)->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function workOrderItems(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }
}

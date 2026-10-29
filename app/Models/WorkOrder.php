<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reception_id',
        'budget_id',
        'mechanic_id',
        'created_by',
        'code',
        'status',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Con withTrashed: la OT conserva su responsable aunque el mecánico se elimine.
     */
    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(Mechanic::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(WorkOrderPart::class);
    }

    public function closing(): HasOne
    {
        return $this->hasOne(WorkOrderClosing::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(WorkOrderCommission::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Versiones del reparto económico (M8), en orden.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(WorkOrderAllocation::class)->orderBy('version');
    }

    /**
     * Reparto confirmado vigente (como máximo uno).
     */
    public function confirmedAllocation(): HasOne
    {
        return $this->hasOne(WorkOrderAllocation::class)->where('status', AllocationStatus::CONFIRMED->value);
    }

    /**
     * Factura vigente (borrador o emitida); las anuladas no cuentan.
     */
    public function currentInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->whereIn('status', InvoiceStatus::currentStatuses())
        );
    }
}

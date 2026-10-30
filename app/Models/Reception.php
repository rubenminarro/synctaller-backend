<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\ClaimStatus;
use App\Enums\ReceptionStatus;

class Reception extends Model
{
    
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'vehicle_id',
        'reception_date',
        'estimated_delivery_date',
        'mileage',
        'fuel_level',
        'problem_description',
        'observations',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'created_by',
    ];

    protected $casts = [
        'status' => ReceptionStatus::class,
        'reception_date' => 'date',
        'estimated_delivery_date' => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checkList(): HasOne
    {
        return $this->hasOne(ReceptionCheckList::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReceptionPhoto::class);
    }

    public function diagnostics(): HasMany
    {
        return $this->hasMany(Diagnostic::class);
    }

    /**
     * Siniestros de la recepción, incluidos los cancelados (historial).
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /**
     * Siniestro vigente (no cancelado): como máximo uno por recepción.
     */
    public function currentClaim(): HasOne
    {
        return $this->hasOne(Claim::class)->where('status', '!=', ClaimStatus::CANCELLED->value);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function serviceCategories(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(VehicleDelivery::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

}

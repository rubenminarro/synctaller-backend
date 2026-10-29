<?php

namespace App\Models;

use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Siniestro de una recepción (M3). Datos del asegurado y del perito,
 * deducible (nulo = desconocido) y estado sincronizado con la recepción.
 */
class Claim extends Model
{
    use Auditable;

    protected $fillable = [
        'code',
        'reception_id',
        'insurer_id',
        'claim_number',
        'policy_number',
        'claim_type',
        'loss_date',
        'loss_description',
        'insured_client_id',
        'insured_name',
        'insured_document',
        'insured_phone',
        'insured_email',
        'adjuster_name',
        'adjuster_phone',
        'adjuster_email',
        'inspection_scheduled_at',
        'inspected_at',
        'deductible_amount',
        'coverage_notes',
        'status',
        'closed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'claim_type' => ClaimType::class,
            'status' => ClaimStatus::class,
            'loss_date' => 'date',
            'inspection_scheduled_at' => 'datetime',
            'inspected_at' => 'datetime',
            'deductible_amount' => 'decimal:2',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class)->withTrashed();
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class)->withTrashed();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ClaimDocument::class)->latest('id');
    }

    public function insuredClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'insured_client_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }
}

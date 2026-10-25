<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Enums\DiagnosticItemTemplateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ítem de diagnóstico reutilizable (catálogo). El ítem del diagnóstico copia sus valores al elegirlo.
 */
class DiagnosticItemTemplate extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'code',
        'service_category_id',
        'title',
        'description',
        'severity',
        'requires_repair',
        'requires_replacement',
        'estimated_time',
        'recommendation',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => DiagnosticItemTemplateStatus::class,
            'requires_repair' => 'boolean',
            'requires_replacement' => 'boolean',
            'estimated_time' => 'integer',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === DiagnosticItemTemplateStatus::ACTIVE;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function diagnosticItems(): HasMany
    {
        return $this->hasMany(DiagnosticItem::class);
    }
}

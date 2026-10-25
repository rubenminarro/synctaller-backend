<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiagnosticItem extends Model
{
    protected $fillable = [
        'diagnostic_id',
        'diagnostic_item_template_id',
        'title',
        'description',
        'severity',
        'status',
        'requires_repair',
        'requires_replacement',
        'estimated_cost',
        'estimated_time',
        'recommendation',
    ];

    protected $casts = [
        'requires_repair' => 'boolean',
        'requires_replacement' => 'boolean',
        'estimated_cost' => 'decimal:2',
    ];

    public function diagnostic(): BelongsTo
    {
        return $this->belongsTo(Diagnostic::class);
    }

    /**
     * Entrada del catálogo de la que se copió (aunque luego se haya eliminado).
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DiagnosticItemTemplate::class, 'diagnostic_item_template_id')->withTrashed();
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DiagnosticItemPhoto::class);
    }
}

<?php

namespace App\Models;

use App\Enums\InsurerStatus;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Aseguradora: datos propios (contactos, plazo de pago, estado) vinculados 1:1
 * a un cliente empresa, que es el destinatario de sus facturas.
 */
class Insurer extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'client_id',
        'code',
        'status',
        'payment_term_days',
        'notes',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => InsurerStatus::class,
            'payment_term_days' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(InsurerContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}

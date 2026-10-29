<?php

namespace App\Models;

use App\Enums\ClientAcceptanceKind;
use App\Enums\ClientDecisionChannel;
use App\Exceptions\BudgetException;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidencia de la respuesta del cliente a un presupuesto. Inmutable: una
 * respuesta nueva es otra fila (reabrir no borra las anteriores).
 */
class BudgetClientAcceptance extends Model
{
    use Auditable;

    protected $fillable = [
        'budget_id',
        'kind',
        'decided_by',
        'decided_at',
        'channel',
        'contact',
        'notes',
        'owner_amount',
        'insurer_authorization_id',
        'deductible_amount',
    ];

    protected $casts = [
        'kind' => ClientAcceptanceKind::class,
        'channel' => ClientDecisionChannel::class,
        'decided_at' => 'datetime',
        'owner_amount' => 'decimal:2',
        'deductible_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        $immutable = function () {
            throw new BudgetException(
                'Las respuestas del cliente a un presupuesto no pueden modificarse ni eliminarse.',
                409,
                'BUDGET_CLIENT_ACCEPTANCE_IMMUTABLE'
            );
        };

        static::updating($immutable);
        static::deleting($immutable);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Autorización de la aseguradora vigente al responder (M7).
     */
    public function insurerAuthorization(): BelongsTo
    {
        return $this->belongsTo(InsurerAuthorization::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}

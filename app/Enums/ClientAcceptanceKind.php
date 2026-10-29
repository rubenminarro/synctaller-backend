<?php

namespace App\Enums;

/**
 * Respuesta del cliente a un presupuesto (budget_client_acceptances). La
 * re-aceptación confirma, con el presupuesto aprobado, el importe actual a su
 * cargo en un siniestro (M7).
 */
enum ClientAcceptanceKind: string
{
    case APPROVAL = 'approval';
    case REJECTION = 'rejection';
    case RE_ACCEPTANCE = 're_acceptance';

    public function label(): string {
        return match($this) {
            self::APPROVAL => 'Aprobación',
            self::REJECTION => 'Rechazo',
            self::RE_ACCEPTANCE => 'Re-aceptación',
        };
    }

    /**
     * Las que fijan el importe aceptado por el dueño (la última vale).
     */
    public static function acceptances(): array
    {
        return [self::APPROVAL, self::RE_ACCEPTANCE];
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enums;

/**
 * Respuesta del cliente a un presupuesto (budget_client_acceptances).
 */
enum ClientAcceptanceKind: string
{
    case APPROVAL = 'approval';
    case REJECTION = 'rejection';

    public function label(): string {
        return match($this) {
            self::APPROVAL => 'Aprobación',
            self::REJECTION => 'Rechazo',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enums;

/**
 * Resultado de una autorización, calculado de sus líneas: todas autorizadas, ninguna cubierta o el resto.
 */
enum InsurerAuthorizationResult: string
{
    case FULL = 'full';
    case PARTIAL = 'partial';
    case REJECTED = 'rejected';

    public function label(): string {
        return match($this) {
            self::FULL => 'Total',
            self::PARTIAL => 'Parcial',
            self::REJECTED => 'Rechazada',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

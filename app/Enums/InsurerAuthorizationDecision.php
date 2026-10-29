<?php

namespace App\Enums;

/**
 * Decisión de la aseguradora sobre una línea del presupuesto.
 */
enum InsurerAuthorizationDecision: string
{
    case AUTHORIZED = 'authorized';
    case PARTIAL = 'partial';
    case NOT_COVERED = 'not_covered';

    public function label(): string {
        return match($this) {
            self::AUTHORIZED => 'Autorizada',
            self::PARTIAL => 'Parcial',
            self::NOT_COVERED => 'No cubierta',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

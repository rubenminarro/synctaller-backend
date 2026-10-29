<?php

namespace App\Enums;

/**
 * Vigencia de una autorización: la vigente, una sustituida por otra versión o una invalidada al reabrir o cancelar el presupuesto.
 */
enum InsurerAuthorizationStatus: string
{
    case CURRENT = 'current';
    case SUPERSEDED = 'superseded';
    case INVALIDATED = 'invalidated';

    public function label(): string {
        return match($this) {
            self::CURRENT => 'Vigente',
            self::SUPERSEDED => 'Sustituida',
            self::INVALIDATED => 'Invalidada',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

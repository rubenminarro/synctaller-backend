<?php

namespace App\Enums;

/**
 * Siniestro propio (cubre la aseguradora del dueño) o de tercero (la del
 * responsable; el asegurado es el otro conductor).
 */
enum ClaimType: string
{
    case OWN = 'own';
    case THIRD_PARTY = 'third_party';

    public function label(): string {
        return match($this) {
            self::OWN => 'Propio',
            self::THIRD_PARTY => 'De tercero',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

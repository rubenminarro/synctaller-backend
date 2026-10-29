<?php

namespace App\Enums;

/**
 * Porción de un concepto vendido en el reparto (capa A, sin IVA): la que paga la aseguradora hasta lo autorizado, la
 * del dueño (su línea o un extra), lo que la aseguradora no autorizó (a cargo del dueño) y lo que absorbe el taller con
 * una excepción interna.
 */
enum AllocationPortionType: string
{
    case INSURER_COVERED = 'insurer_covered';
    case OWNER_RESPONSIBILITY = 'owner_responsibility';
    case OWNER_UNCOVERED = 'owner_uncovered';
    case WORKSHOP_ABSORBED = 'workshop_absorbed';

    public function label(): string {
        return match($this) {
            self::INSURER_COVERED => 'Cubierto por la aseguradora',
            self::OWNER_RESPONSIBILITY => 'A cargo del dueño',
            self::OWNER_UNCOVERED => 'No cubierto (a cargo del dueño)',
            self::WORKSHOP_ABSORBED => 'Absorbido por el taller',
        };
    }

    public function isOwner(): bool
    {
        return in_array($this, [self::OWNER_RESPONSIBILITY, self::OWNER_UNCOVERED], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

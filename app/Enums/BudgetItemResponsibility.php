<?php

namespace App\Enums;

/**
 * Quién paga una línea del presupuesto: el dueño (siempre, sin siniestro) o la
 * aseguradora del siniestro abierto de la recepción.
 */
enum BudgetItemResponsibility: string
{
    case OWNER = 'owner';
    case INSURER = 'insurer';

    public function label(): string {
        return match($this) {
            self::OWNER => 'Dueño',
            self::INSURER => 'Aseguradora',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enums;

/**
 * Registro de una nota de crédito emitida fuera del sistema (P10): vigente o
 * anulado por haberse cargado mal.
 */
enum CreditNoteStatus: string
{
    case REGISTERED = 'registered';
    case CANCELLED = 'cancelled';

    public function label(): string {
        return match($this) {
            self::REGISTERED => 'Registrada',
            self::CANCELLED => 'Anulada',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

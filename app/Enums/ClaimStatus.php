<?php

namespace App\Enums;

/**
 * Estado del siniestro. Solo tres: el avance (autorizado, facturado…) se calcula
 * de las autorizaciones, el reparto y las facturas. Lo mueve ClaimStatusService.
 */
enum ClaimStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    public function label(): string {
        return match($this) {
            self::OPEN => 'Abierto',
            self::CLOSED => 'Cerrado',
            self::CANCELLED => 'Cancelado',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

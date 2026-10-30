<?php

namespace App\Enums;

/**
 * Cómo se factura el deducible de un siniestro (M10). La aseguradora tiene una por defecto y el siniestro la copia al
 * abrirse (puede variar según la póliza). Sin modalidad (null) rige la regla A: con deducible mayor a 0 no se factura.
 *
 * - net_of_deductible: la aseguradora recibe lo cubierto menos el deducible; el dueño, el deducible en una línea propia
 *   de su factura («Deducible de siniestro N.º …»).
 */
enum DeductibleBillingMode: string
{
    case NET_OF_DEDUCTIBLE = 'net_of_deductible';

    public function label(): string {
        return match($this) {
            self::NET_OF_DEDUCTIBLE => 'Neta del deducible',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

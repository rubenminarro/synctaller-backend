<?php

namespace App\Enums;

/**
 * Estado de cobro de una factura emitida (calculado, no se persiste). Acreditada:
 * saldada solo con notas de crédito, sin ningún pago (P10).
 */
enum InvoicePaymentStatus: string
{
    case UNPAID = 'unpaid';
    case PARTIAL = 'partial';
    case PAID = 'paid';
    case CREDITED = 'credited';

    public function label(): string {
        return match($this) {
            self::UNPAID => 'Pendiente',
            self::PARTIAL => 'Pago parcial',
            self::PAID => 'Pagada',
            self::CREDITED => 'Acreditada',
        };
    }
}

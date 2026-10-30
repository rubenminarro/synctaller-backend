<?php

namespace App\Enums;

/**
 * Tipo de destinatario de una factura en los reportes (M11): el cliente empresa
 * de una aseguradora (también si la aseguradora está dada de baja) o cualquier
 * otro cliente.
 */
enum InvoiceRecipientType: string
{
    case INSURER = 'insurer';
    case CUSTOMER = 'customer';

    public function label(): string {
        return match($this) {
            self::INSURER => 'Aseguradora',
            self::CUSTOMER => 'Cliente',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

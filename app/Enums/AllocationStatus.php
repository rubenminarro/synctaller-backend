<?php

namespace App\Enums;

/**
 * Versión del reparto económico de una OT: el borrador que se edita, la confirmada (vigente) y la sustituida por otra
 * versión confirmada.
 */
enum AllocationStatus: string
{
    case DRAFT = 'draft';
    case CONFIRMED = 'confirmed';
    case SUPERSEDED = 'superseded';

    public function label(): string {
        return match($this) {
            self::DRAFT => 'Borrador',
            self::CONFIRMED => 'Confirmado',
            self::SUPERSEDED => 'Sustituido',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

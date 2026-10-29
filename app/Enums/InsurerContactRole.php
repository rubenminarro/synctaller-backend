<?php

namespace App\Enums;

/**
 * Para qué se llama a cada contacto de una aseguradora.
 */
enum InsurerContactRole: string
{
    case CLAIMS = 'claims';
    case BILLING = 'billing';
    case ADJUSTER_COORDINATION = 'adjuster_coordination';
    case OTHER = 'other';

    public function label(): string {
        return match($this) {
            self::CLAIMS => 'Siniestros',
            self::BILLING => 'Facturación',
            self::ADJUSTER_COORDINATION => 'Coordinación de peritos',
            self::OTHER => 'Otro',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

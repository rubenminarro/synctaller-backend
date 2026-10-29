<?php

namespace App\Enums;

/**
 * Excepción interna del flujo de siniestros (decisión C): ninguna sube lo que
 * paga la aseguradora ni lo que se cobra al dueño.
 */
enum InternalExceptionType: string
{
    // Sobre el presupuesto: crear la OT sin la autorización de la aseguradora (no reemplaza la aceptación del dueño).
    case START_WITHOUT_AUTHORIZATION = 'start_without_authorization';
    // Sobre la OT: el taller absorbe una parte del dueño (se usa en el reparto, M8).
    case WORKSHOP_ABSORPTION = 'workshop_absorption';

    public function label(): string {
        return match($this) {
            self::START_WITHOUT_AUTHORIZATION => 'Iniciar sin autorización',
            self::WORKSHOP_ABSORPTION => 'Absorción del taller',
        };
    }

    /**
     * Tipo de documento sobre el que se otorga (alias del morph map).
     */
    public function subjectType(): string
    {
        return match($this) {
            self::START_WITHOUT_AUTHORIZATION => 'budget',
            self::WORKSHOP_ABSORPTION => 'work_order',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

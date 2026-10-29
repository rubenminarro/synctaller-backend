<?php

namespace App\Enums;

/**
 * Estado de una aseguradora. Inactiva: no admite siniestros nuevos (M3).
 */
enum InsurerStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string {
        return match($this) {
            self::ACTIVE => 'Activa',
            self::INACTIVE => 'Inactiva',
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return $this !== $status;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

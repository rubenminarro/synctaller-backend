<?php

namespace App\Enums;

enum AuditEvent: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case DELETED = 'deleted';
    case RESTORED = 'restored';
    case LOGIN = 'login';
    case LOGIN_FAILED = 'login_failed';
    case LOGOUT = 'logout';
    case PERMISSIONS_SYNCED = 'permissions_synced';
    case ROLES_SYNCED = 'roles_synced';
    case DOWNLOADED = 'downloaded';

    public function label(): string {
        return match($this) {
            self::CREATED => 'Creado',
            self::UPDATED => 'Modificado',
            self::DELETED => 'Eliminado',
            self::RESTORED => 'Restaurado',
            self::LOGIN => 'Inicio de sesión',
            self::LOGIN_FAILED => 'Inicio de sesión fallido',
            self::LOGOUT => 'Cierre de sesión',
            self::PERMISSIONS_SYNCED => 'Permisos del rol actualizados',
            self::ROLES_SYNCED => 'Roles del usuario actualizados',
            self::DOWNLOADED => 'Descargado',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

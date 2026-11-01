# Administración del sistema

Todos **COMPLETADOS** según el [criterio del índice](README.md#criterio).

## Autenticación

| Campo | Detalle |
|---|---|
| Objetivo | Identificar a cada usuario |
| Implementado | Login y logout con tokens Sanctum, `GET /me` con permisos y preferencias |
| Pendiente | Límite de intentos en el login, identificado en la revisión del spike (handoff 2.80) |
| Reglas | `CLAUDE.md`, sección 4 |
| Tests | `Login` 9 |
| Observaciones | Los tokens no expiran |

## Usuarios, roles y permisos

| Campo | Detalle |
|---|---|
| Objetivo | Controlar quién puede hacer qué |
| Implementado | ABM de usuarios y roles, seis roles del taller, permisos por operación |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md`, sección 5 y 6.20 |
| Tests | `UserManagement` 5, `RoleAndPermissionManagement` 7, `WorkshopRoles` 7, `PermissionsGroupedByModule` 2 |
| Observaciones | Probar los permisos con un rol propio, porque el administrador pasa todo |

## Auditoría (FASE 25)

| Campo | Detalle |
|---|---|
| Objetivo | Registrar quién cambió qué y cuándo |
| Implementado | Trait `Auditable`, eventos propios, consulta para el administrador |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.19 |
| Depende de | Todos los modelos con datos sensibles |
| Tests | `AuditLog` 6 |
| Observaciones | Las actualizaciones masivas por query builder no se auditan |

## Preferencias y logo

| Campo | Detalle |
|---|---|
| Objetivo | Personalizar el tema y la identidad |
| Implementado | Preferencias por usuario, logo con versión negra, favicon |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.21 |
| Tests | `UserPreference` 7, `SystemLogo` 10, `SystemFavicon` 5 |

## Transversales

| Tema | Tests |
|---|---|
| Respuesta siempre en JSON y CORS | `JsonResponse` 3, `Cors` 3 |
| Datos de ejemplo | `DatabaseSeeder` 1 |
| Zona horaria | `Timezone` 3 |

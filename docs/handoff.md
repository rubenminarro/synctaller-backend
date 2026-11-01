# Handoff de SyncTaller

Documento principal para retomar el desarrollo desde cualquier computadora, con o sin el contexto de sesiones anteriores.

> **Sesión nueva de Claude Code:** leer este documento y [`CLAUDE.md`](../CLAUDE.md), verificar el estado de Git y continuar desde
> [Próximas tareas](#próximas-tareas). No hace falta leer el historial completo: [`README.md`](README.md) indica dónde buscar cada tema.

Si este documento contradice al código, manda el código.

## Punto de continuidad

| Dato | Valor |
|---|---|
| Actualizado | 2026-11-01 |
| Backend | `main`, último commit de código `18e2bfa`; después, solo documentación y Skills |
| Frontend | `main` en `c49efec` |
| Última entrega de código | Hora de Paraguay en toda la aplicación (deuda #17, handoff 2.79) |
| Última entrega técnica | Revisión del spike T0 y decisiones TD1–TD11 (handoff 2.80) |
| Tests del backend | 489 en verde, 8561 aserciones |
| Tests del frontend | 43 en verde |
| Rutas API | 284 |
| Verificación | Suites corridas el 2026-11-01. El estado actual siempre se confirma con `git log origin/main` y `php artisan test` |

## Estado del proyecto

SyncTaller es la API de gestión de un taller mecánico, con el frontend en otro repositorio. El roadmap original (FASES 10–25) está
completo. Hay dos líneas abiertas.

| Línea | Estado |
|---|---|
| **Aseguradoras y siniestros** | M1–M9, M11 (sin los avisos) y P10 hechas. M10 hecha, con un interruptor apagado. Falta M12 |
| **Infraestructura SaaS** | T0 y T1 hechas. Decisiones para T2 aprobadas. T2–T6 sin implementar |

**El sistema no está listo para producción.** Detalle por módulo en [`modules/README.md`](modules/README.md) y por fase en
[`roadmap/README.md`](roadmap/README.md).

## Próximas tareas

| Orden | Tarea | Bloqueo |
|---|---|---|
| 1 | **T2, paso 1:** base central, paquete y modelo `Tenant` | No antes del **2026-11-09**: la versión 4.2.3 necesita dos semanas publicada (TD2). Empezar repitiendo la medición con esa versión ([`spike-t0/README.md`](spike-t0/README.md)). Instalar el paquete en `main` requiere la aprobación del usuario |
| 2 | Cuestionario para el contador | Falta prepararlo. Destraba encender M10 (V21, V16) y M12 (V17) |
| 3 | Aprobar el diseño de M12 (retenciones) | Decisión del usuario; implementarla espera V17 |
| 4 | Resto de T2 a T5, según el plan de [`handoff-05`](decisions/historial/handoff-05-decisiones-por-entrega.md), sección 2.80 | Tras el paso 1 |
| 5 | Tarea P8 (fotos y firmas a privado) | Después de T4; requisito antes del primer taller comercial |

Ninguna entrega empieza sin autorización del usuario. Las validaciones externas pendientes están en §10 de la
[especificación](especificacion-aseguradoras.html).

## Decisiones técnicas que hay que tener presentes

El catálogo completo está en [`decisions/README.md`](decisions/README.md).

| Tema | Decisión |
|---|---|
| Multitenancy | Base central más una base por taller, con `spatie/laravel-multitenancy` 4.2.3. Aislada en infraestructura: modelos, controllers y services no la conocen |
| Permisos por taller | La tarea de cambio usa `initializeCache()`, también en HTTP. **Nunca `forgetCachedPermissions()`** |
| Fallo cerrado | `DB_DATABASE` vacío en producción: sin taller activo, una consulta falla |
| Conexión a la central | Persistente solo en la central; elimina la sobrecarga medida |
| Zona horaria | `APP_TIMEZONE`, por defecto `America/Asuncion`. La base guarda hora local y la API envía UTC |
| Deducible mayor a 0 | Implementado detrás de `INSURER_DEDUCTIBLE_NET_MODE`. No se enciende sin la confirmación escrita del contador |
| Notas de crédito | SyncTaller las registra, no las emite (P10) |
| Documentos de siniestros | Disco privado sin URL; toda descarga pasa por la API y se audita |

## Instalar en otra computadora

Procedimiento completo, requisitos y diferencias entre equipos: [`development/setup.md`](development/setup.md). Ejecución de tests:
[`development/testing.md`](development/testing.md).

```bash
# Backend
git clone https://github.com/rubenminarro/synctaller-backend.git
cd synctaller-backend
composer install
cp .env.example .env
php artisan key:generate
# Completar el .env con valores propios y crear la base en utf8mb4
php artisan migrate --seed
php artisan test

# Frontend, en una carpeta hermana
git clone https://github.com/rubenminarro/synctaller-frontend.git
cd synctaller-frontend
npm ci
```

En un equipo ya instalado alcanza con `git pull`, `composer install`, `php artisan migrate` y, en el frontend, `npm ci`.

**Nunca `npm install`:** reescribe el lock y separa los equipos. Tampoco `config:cache` en desarrollo: los tests correrían contra
MySQL.

## Instrucciones para retomar

1. Verificar el estado: `git status`, `git log --oneline -5` y `git fetch`, en los dos repositorios.
2. Comparar con el [punto de continuidad](#punto-de-continuidad). Si hay commits nuevos, leer la última sección de
   [`handoff-05`](decisions/historial/handoff-05-decisiones-por-entrega.md).
3. Correr `php artisan test` y anotar el resultado. Tarda unos 6 minutos.
4. Revisar [Próximas tareas](#próximas-tareas), confirmar con el usuario qué sigue y crear una rama desde `origin/main`.
5. Seguir la metodología de `CLAUDE.md`, sección 2: commit, push y merge solo a pedido del usuario.

## Mapa de secciones antiguas

Hasta el commit `9cae759` este archivo tenía 4.575 líneas. `CLAUDE.md`, el `README.md` raíz y comentarios del código siguen citando
sus secciones por número, así que el contenido se dividió **sin reescribirlo**, conservando la numeración.

| Sección citada | Tema | Ubicación actual |
|---|---|---|
| Cabecera, 1 a 1.5 | Visión, stack y arquitectura originales | [`handoff-01`](decisions/historial/handoff-01-vision-y-arquitectura.md) |
| 2, 2.2 a 2.23 | Estado global y módulos base | [`handoff-02`](decisions/historial/handoff-02-modulos-base.md) |
| 2.24 a 2.38 | FASES 11 a 25 | [`handoff-03`](decisions/historial/handoff-03-fases-11-a-25.md) |
| 2.39 a 2.53 | Ajustes de contrato, Laravel 13, escenarios, catálogo de diagnóstico | [`handoff-04`](decisions/historial/handoff-04-ajustes-de-contrato.md) |
| 2.54 en adelante | Correcciones, aseguradoras, T1, zona horaria y spike | [`handoff-05`](decisions/historial/handoff-05-decisiones-por-entrega.md) |
| 3 y 4 | Convenciones y configuración | [`handoff-06`](decisions/historial/handoff-06-convenciones-y-configuracion.md) |
| 5 y 6 | Diseño y roadmap original | [`handoff-07`](decisions/historial/handoff-07-roadmap-original.md) |
| 7 | Memoria técnica | [`handoff-08`](decisions/historial/handoff-08-memoria-tecnica.md) |
| 8 a 12 | Checklist, principios y cierre original | [`handoff-09`](decisions/historial/handoff-09-cierre-original.md) |
| 13 | Regla de oro | [`project/principios.md`](project/principios.md) |
| 14 y 14.1 | Deuda técnica y discrepancias D1–D10 | [`roadmap/deuda-tecnica.md`](roadmap/deuda-tecnica.md) |

El registro de continuidad de octubre de 2026 está en
[`continuidad-octubre-2026.md`](decisions/historial/continuidad-octubre-2026.md).

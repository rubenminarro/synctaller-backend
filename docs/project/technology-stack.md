# Tecnologías

Versiones leídas de `composer.lock` y de la instalación del frontend el 2026-11-01. Ante una duda, mandan los lockfiles.

## Backend

| Componente | Versión | Uso |
|---|---|---|
| PHP | `^8.3` | Requisito del proyecto |
| `laravel/framework` | 13.39.0 | Framework |
| `laravel/sanctum` | 4.3.3 | Tokens Bearer |
| `spatie/laravel-permission` | 8.3.2 | Roles y permisos |
| `barryvdh/laravel-dompdf` | 3.1.2 | PDF, con `dompdf/dompdf` 3.1.6 |
| `phpunit/phpunit` | 12.5.42 | Tests |
| MySQL | 8 | Base de desarrollo; los tests usan SQLite en archivo |

## Frontend

| Componente | Versión |
|---|---|
| React | 19.3.1 |
| TypeScript | 6.0.3 |
| Vite | 8.3.5 |
| React Router | 7.18.5 |
| TanStack Query | 5.105.1 |
| Tabler | 1.7.3 |
| ApexCharts | 8.7.0 |
| Vitest | 5.0.5 |

El detalle del frontend está en su propio `CLAUDE.md`.

## Dependencias retenidas a propósito

| Paquete | Sigue en | Motivo |
|---|---|---|
| `typescript` | 6.0.x | `typescript-eslint` no admite TypeScript 7 todavía |
| `@types/node` | 24 | El runtime es Node 22 |
| `jsdom` | 29.1.1 | La 30 pide Node 22.22 o más |

## Licencia a revisar

`apexcharts` 8 y `react-apexcharts` 2 ya no son MIT: tienen licencia dual, y la OEM paga puede aplicar a un SaaS con gráficos
interactivos. Hay que revisarla antes del primer taller comercial (handoff 2.77).

## Multitenancy

`spatie/laravel-multitenancy` **no está instalado**. La versión elegida para T2 es la 4.2.3 (TD2); instalarla en `main` requiere la
aprobación del usuario.

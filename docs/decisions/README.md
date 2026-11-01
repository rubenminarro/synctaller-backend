# Decisiones

Índice de las decisiones técnicas y de negocio. No repite su contenido: cada fila apunta a donde está el detalle y el motivo.

## Series de decisiones

Las siglas se conservan tal como se usaron en el proyecto. Ojo: `D` (decisión de avisos) y `D1–D10` (discrepancias) son cosas distintas.

| Serie | Tema | Dónde está |
|---|---|---|
| A a G | Revisión de la especificación: deducible, aceptación del dueño, excepciones, avisos, documentos, facturas de la OT, umbrales | [Especificación](../especificacion-aseguradoras.html), §10.2 |
| P1 a P9 | Propuestas de la especificación: tenancy, empresas, correlativos, extras, repartos, topes, archivos, suspensión | Especificación, §10.2 |
| P10 | Notas de crédito emitidas fuera del sistema | Especificación, §10.2; handoff 2.66 |
| G1 a G10 | Preguntas externas | Especificación, §10.1 |
| V1 a V22 | Validaciones y precisiones externas, con sus cierres internos | Especificación, §10.1; handoff 2.67 |
| R1 a R11 | Invariantes del reparto y la facturación | Especificación, §6.2; `CLAUDE.md` 6.27 y 6.31 |
| S1 a S13 | Criterios del spike | Especificación, §3.13; [informe](../spike-t0/informe.md) |
| TD1 a TD11 | Decisiones para T2 | Especificación, §10.2; handoff 2.80 |
| D1 a D10 | Discrepancias del estudio, no decisiones | [Deuda técnica](../roadmap/deuda-tecnica.md), 14.1 |
| #1 a #17 | Deudas técnicas | [Deuda técnica](../roadmap/deuda-tecnica.md) |

## Decisiones por tema

| Tema | Sección del historial |
|---|---|
| Convenciones de diseño | 3 |
| Repuestos extra con aprobación del cliente | 2.49 |
| Migración a Laravel 13 | 2.51 |
| Datos de ejemplo por escenarios | 2.52 |
| Códigos internos con bloqueo | 2.55 |
| Importes en `decimal(14,2)` | 2.56 |
| Spike T0 | 2.58, 2.80 |
| Modalidad neta del deducible | 2.75 |
| Discrepancias D3, D7 y D8 | 2.74 |
| Actualización de dependencias y licencia de ApexCharts | 2.77 |
| Configuración del taller | 2.78 |
| Hora de Paraguay | 2.79 |

La ubicación de cada sección está en el [mapa del handoff](../handoff.md#mapa-de-secciones-antiguas).

## Historial

Registro cronológico, sin reescribir. Describe lo que era cierto cuando se escribió: para el estado actual, usar los documentos vivos.

| Archivo | Contenido |
|---|---|
| [`handoff-01-vision-y-arquitectura.md`](historial/handoff-01-vision-y-arquitectura.md) | Visión y arquitectura originales |
| [`handoff-02-modulos-base.md`](historial/handoff-02-modulos-base.md) | Módulos base y FASE 9 |
| [`handoff-03-fases-11-a-25.md`](historial/handoff-03-fases-11-a-25.md) | FASES 11 a 25 |
| [`handoff-04-ajustes-de-contrato.md`](historial/handoff-04-ajustes-de-contrato.md) | Ajustes de contrato y datos |
| [`handoff-05-decisiones-por-entrega.md`](historial/handoff-05-decisiones-por-entrega.md) | Desde la 2.54. **Sigue creciendo** |
| [`handoff-06-convenciones-y-configuracion.md`](historial/handoff-06-convenciones-y-configuracion.md) | Convenciones y configuración |
| [`handoff-07-roadmap-original.md`](historial/handoff-07-roadmap-original.md) | Diseño y roadmap original |
| [`handoff-08-memoria-tecnica.md`](historial/handoff-08-memoria-tecnica.md) | Memoria técnica |
| [`handoff-09-cierre-original.md`](historial/handoff-09-cierre-original.md) | Checklist y cierre del handoff original |
| [`continuidad-octubre-2026.md`](historial/continuidad-octubre-2026.md) | Registro de continuidad, con enlaces a las páginas publicadas |

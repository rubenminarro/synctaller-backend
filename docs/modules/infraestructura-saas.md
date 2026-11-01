# Infraestructura SaaS

**Estado: EN DESARROLLO.** Operar varios talleres, con una base por taller. **No hay código de multitenancy en `main`**; lo hecho
hasta ahora es la evaluación y la configuración por taller.

| Dato | Referencia |
|---|---|
| Diseño | [`especificacion-aseguradoras.html`](../especificacion-aseguradoras.html), §3 y §9 |
| Evaluación y medición | [`spike-t0/informe.md`](../spike-t0/informe.md) |
| Decisiones TD1–TD11 y plan | [`handoff-05`](../decisions/historial/handoff-05-decisiones-por-entrega.md), sección 2.80 |

## Estado por entrega

| Entrega | Objetivo | Estado | Observaciones |
|---|---|---|---|
| T0 | Evaluar el paquete | COMPLETADO | Viable. Paso 0 medido el 2026-11-01 |
| T1 | Configuración del taller en la base | COMPLETADO | `CLAUDE.md` 6.32. Tests: `WorkshopSettings` 14 |
| T2 | Base central e identificación | PENDIENTE | Desde el 2026-11-09. Requiere aprobar la instalación en `main` |
| T3 | Colas, cache, permisos y scheduler | PENDIENTE | Depende de T2 |
| T4 | Storage por taller | PENDIENTE | Depende de T3 |
| Tarea P8 | Fotos y firmas a privado | PENDIENTE | Después de T4; deuda #15 |
| T5 | Ciclo de vida | PENDIENTE | Incluye `tenants:migrate` y suspensión |
| T6 | Migrar la instalación actual | PENDIENTE | Requiere T1 a T5, P8 y decidir el hosting (V9) |

## Observaciones para continuar

* La línea T no bloquea a la línea de aseguradoras.
* `ApplyWorkshopSettings`, `Queue::before` y el comando de recordatorios aplican hoy la configuración de T1 en tres lugares: se
  unifican en T2.
* La medición se repite con la versión 4.2.3 antes de instalarla. Las herramientas están en [`spike-t0/`](../spike-t0/README.md).

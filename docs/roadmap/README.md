# Roadmap

Fuente de verdad del estado por fase y entrega. Verificado el 2026-11-01 contra el código. Las fases conservan su numeración
original y no se renumeran. El detalle por módulo está en [`modules/README.md`](../modules/README.md).

## Módulos base y FASES 9 a 25

| Fase | Contenido | Estado |
|---|---|---|
| Hasta FASE 9 | Autenticación, usuarios, clientes, vehículos, recepción, diagnóstico, presupuestos, OT, respuestas y excepciones | COMPLETADO |
| FASE 9.14 | Auditoría de excepciones y controllers | COMPLETADO |
| FASE 10 | Catálogo de repuestos, categorías y proveedores | COMPLETADO |
| FASE 11 | Inventario | COMPLETADO |
| FASE 12 | Repuestos en la OT | COMPLETADO |
| FASE 13 | Servicios y mano de obra | COMPLETADO |
| FASE 14 | Cierre económico | COMPLETADO |
| FASE 15 | Facturación | COMPLETADO |
| FASE 16 | Pagos | COMPLETADO |
| FASE 17 | Entrega del vehículo | COMPLETADO |
| FASE 18 | Historial del vehículo | COMPLETADO |
| FASE 19 | Mantenimiento preventivo | COMPLETADO |
| FASE 20 | Reportes y dashboard | COMPLETADO |
| FASE 21 | Compras | COMPLETADO |
| FASE 22 | Pagos a proveedores | COMPLETADO |
| FASE 23 | Notificaciones por email y bandeja interna | COMPLETADO |
| FASE 24 | Exportación y comprobantes | COMPLETADO |
| FASE 25 | Auditoría | COMPLETADO |

Entre fases se hicieron además preferencias y logo, datos de ejemplo por escenarios, catálogo de ítems de diagnóstico y actualizaciones
de Laravel y dependencias (handoff 2.39 a 2.53, 2.77 y 2.79).

El diseño original de estas fases se conserva en
[`handoff-07`](../decisions/historial/handoff-07-roadmap-original.md) como historia.

## Módulo de aseguradoras

Plan de §9 de la [especificación](../especificacion-aseguradoras.html).

| Línea | Entrega | Estado |
|---|---|---|
| E | E0 a E5: correcciones previas | COMPLETADO |
| M | M1 a M9 y P10 | COMPLETADO |
| M | M10: deducible facturado | COMPLETADO, con el interruptor apagado |
| M | M11: reportes | COMPLETADO |
| M | M11: avisos a la aseguradora | PENDIENTE (V7) |
| M | M12: retenciones de IVA | PENDIENTE (diseño sin aprobar, V17) |

Frontend: FA0 a FA4 y M10 completos ([detalle](../modules/aseguradoras.md)).

## Línea T: infraestructura SaaS

| Entrega | Estado |
|---|---|
| T0: spike | COMPLETADO |
| T1: configuración en la base | COMPLETADO |
| T2: central e identificación | PENDIENTE, desde el 2026-11-09 |
| T3: colas, cache, permisos y scheduler | PENDIENTE |
| T4: storage | PENDIENTE |
| Tarea P8: fotos y firmas a privado | PENDIENTE |
| T5: ciclo de vida | PENDIENTE |
| T6: migrar la instalación actual | PENDIENTE |

Los pasos 0 a 7 de la revisión del spike son internos de T2, T3 y T5, no fases nuevas (handoff 2.80). El paso 0 está hecho.

## Pendiente sin fase

| Tema | Estado |
|---|---|
| Notificaciones por WhatsApp | PENDIENTE, sin diseño |
| Validaciones externas (contador, legal, talleres) | §10 de la especificación |
| Deuda técnica | [`deuda-tecnica.md`](deuda-tecnica.md) |
| Despliegue | [Sin definir](../development/deployment.md) |

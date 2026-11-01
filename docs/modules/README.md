# Estado por módulo

Fuente de verdad del estado de cada módulo. Verificado el 2026-11-01 contra `main`: rutas, código y tests.

## Criterio

| Estado | Significa |
|---|---|
| **COMPLETADO** | Código y rutas presentes, con tests propios que pasan en la suite (489 en verde). Tiene pantallas en el frontend |
| **EN DESARROLLO** | Parte implementada y parte pendiente |
| **PENDIENTE** | Sin código |
| **PENDIENTE DE VERIFICACIÓN** | No se pudo comprobar con certeza |

COMPLETADO no significa listo para producción: el sistema entero sigue sin desplegar. Para el frontend solo se comprobó que existe
la pantalla del módulo y que su suite pasa, no cada flujo.

## Módulos

| Módulo | Fase | Estado | Reglas | Detalle |
|---|---|---|---|---|
| Autenticación | Base | COMPLETADO | `CLAUDE.md` §4 | [Administración](administracion.md) |
| Usuarios, roles y permisos | Base | COMPLETADO | 6.20 | [Administración](administracion.md) |
| Clientes | Base, M1 | COMPLETADO | 6.1 | [Taller](taller.md) |
| Vehículos y su catálogo | Base | COMPLETADO | 6.1 | [Taller](taller.md) |
| Mecánicos | Base | COMPLETADO | 6.1, 6.8 | [Taller](taller.md) |
| Recepción y checklist | Base | COMPLETADO | 6.2 | [Taller](taller.md) |
| Diagnóstico y su catálogo | Base | COMPLETADO | 6.3 | [Taller](taller.md) |
| Presupuestos | Base | COMPLETADO | 6.4 | [Taller](taller.md) |
| Órdenes de trabajo | Base | COMPLETADO | 6.5 | [Taller](taller.md) |
| Catálogo de repuestos y proveedores | FASE 10 | COMPLETADO | 6.6 | [Inventario y compras](inventario-y-compras.md) |
| Inventario | FASE 11 | COMPLETADO | 6.6 | [Inventario y compras](inventario-y-compras.md) |
| Repuestos en la OT | FASE 12 | COMPLETADO | 6.7 | [Inventario y compras](inventario-y-compras.md) |
| Compras | FASE 21 | COMPLETADO | 6.15 | [Inventario y compras](inventario-y-compras.md) |
| Pagos a proveedores | FASE 22 | COMPLETADO | 6.16 | [Inventario y compras](inventario-y-compras.md) |
| Servicios y mano de obra | FASE 13 | COMPLETADO | 6.8 | [Facturación y cobros](facturacion-y-cobros.md) |
| Cierre económico | FASE 14 | COMPLETADO | 6.9 | [Facturación y cobros](facturacion-y-cobros.md) |
| Facturación | FASE 15 | COMPLETADO | 6.10 | [Facturación y cobros](facturacion-y-cobros.md) |
| Pagos | FASE 16 | COMPLETADO | 6.11 | [Facturación y cobros](facturacion-y-cobros.md) |
| Entrega del vehículo | FASE 17 | COMPLETADO | 6.12 | [Postventa y reportes](postventa-y-reportes.md) |
| Historial del vehículo | FASE 18 | COMPLETADO | 6.13 | [Postventa y reportes](postventa-y-reportes.md) |
| Mantenimiento preventivo | FASE 19 | COMPLETADO | 6.13 | [Postventa y reportes](postventa-y-reportes.md) |
| Reportes y dashboard | FASE 20 | COMPLETADO | 6.14 | [Postventa y reportes](postventa-y-reportes.md) |
| Notificaciones por email | FASE 23 | COMPLETADO | 6.17 | [Postventa y reportes](postventa-y-reportes.md) |
| Exportación y comprobantes | FASE 24 | COMPLETADO | 6.18 | [Postventa y reportes](postventa-y-reportes.md) |
| Auditoría | FASE 25 | COMPLETADO | 6.19 | [Administración](administracion.md) |
| Preferencias y logo | Extra | COMPLETADO | 6.21 | [Administración](administracion.md) |
| Configuración del taller | T1 | COMPLETADO | 6.32 | [Infraestructura SaaS](infraestructura-saas.md) |
| **Aseguradoras y siniestros** | M1–M12, P10 | **EN DESARROLLO** | 6.22–6.31 | [Aseguradoras](aseguradoras.md) |
| **Infraestructura SaaS** | T0–T6 | **EN DESARROLLO** | Especificación §3 | [Infraestructura SaaS](infraestructura-saas.md) |
| Notificaciones por WhatsApp | Sin fase | PENDIENTE | — | [Postventa y reportes](postventa-y-reportes.md) |
| Fotos y firmas privadas | Tarea P8 | PENDIENTE | Deuda #15 | [Infraestructura SaaS](infraestructura-saas.md) |

## Fuera de alcance por decisión

No son pendientes del MVP, según §10 de la [especificación](../especificacion-aseguradoras.html): emisión de notas de crédito y SIFEN,
anticipos, convenios y tarifas con aseguradoras, coaseguro y facturas consolidadas de varias OT.

## PENDIENTE DE VERIFICACIÓN

Lo que no se pudo comprobar en esta auditoría.

| Tema | Por qué |
|---|---|
| Guía de Postman completa | No se ejecutó. Faltan comprobar sus cadenas de requests contra la API actual |
| Envío real de emails | En desarrollo el mailer es `log`; solo está probado con `Mail::fake()` |
| Scheduler y cola en un proceso real | Los recordatorios están probados por comando y por test, no con un `schedule:run` sostenido |
| Instalación desde cero | Esta auditoría no repitió el procedimiento en un equipo limpio |
| `composer setup` | No se ejecutó |
| Modalidad neta del deducible con el interruptor encendido | Probada en tests y en una base temporal, nunca en producción |
| Cada flujo del frontend | Solo hay 43 tests. Las revisiones en navegador cubrieron partes |
| Reinicio completo de MySQL con conexión persistente | Solo se probó cortar la conexión |

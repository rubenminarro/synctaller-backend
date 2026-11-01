# Aseguradoras y siniestros

**Estado: EN DESARROLLO.** Reparaciones pagadas por una aseguradora: siniestros, autorizaciones, reparto del costo y facturación por
responsable.

| Dato | Referencia |
|---|---|
| Diseño y validaciones | [`especificacion-aseguradoras.html`](../especificacion-aseguradoras.html), versión 3 |
| Reglas vigentes | `CLAUDE.md` 6.22 a 6.31 |
| Decisiones | [`handoff-05`](../decisions/historial/handoff-05-decisiones-por-entrega.md), secciones 2.59 a 2.75 |
| Depende de | Clientes, recepción, presupuestos, OT, facturación, pagos y entrega |

## Estado por entrega

| Entrega | Objetivo | Estado | Reglas | Tests |
|---|---|---|---|---|
| M1 | Tipo de cliente | COMPLETADO | 6.1 | `ClientKind` 9 |
| M2 | Aseguradoras y contactos | COMPLETADO | 6.22 | `Insurer` 7 |
| M3 | Siniestros | COMPLETADO | 6.23 | `Claim` 8 |
| M4 | Documentos privados | COMPLETADO | 6.24 | `ClaimDocument` 6 |
| M5 | Responsable por línea | COMPLETADO | 6.4 | `BudgetItemResponsibility` 4 |
| M6 | Autorizaciones | COMPLETADO | 6.25 | `InsurerAuthorization` 7 |
| M7 | Bloqueo de OT y excepciones | COMPLETADO | 6.26 | `WorkOrderInsurerFlow` 8 |
| M8 | Reparto económico | COMPLETADO | 6.27 | `WorkOrderAllocation` 9, `WorkOrderSaleBreakdown` 1 |
| P10 | Notas de crédito registradas | COMPLETADO | 6.28 | `CreditNote` 6 |
| M9 | Facturas por responsable | COMPLETADO | 6.29 | `AllocationInvoice` 5 |
| M10 | Deducible facturado, modalidad neta | COMPLETADO, **apagado** | 6.31 | `DeductibleNetMode` 5 |
| M11 | Reportes | COMPLETADO | 6.30 | `InsurerReports` 4 |
| M11 | Avisos a la aseguradora | PENDIENTE | D, V7 | Sin tests |
| M12 | Retenciones de IVA | PENDIENTE | Especificación §9 | Sin código |

El frontend cubre estas entregas (FA0–FA4 y M10): ver `docs/plan-desarrollo.md` en `../synctaller-frontend`.

## Qué falta

| Pendiente | Qué lo destraba |
|---|---|
| Encender M10 | Confirmación escrita del contador (V21) y respuesta a V16 |
| M12 | Aprobar el diseño y respuesta a V17 |
| Avisos a la aseguradora | Decisión comercial (V7, cerrada por ahora como "sin recordatorios") |

## Observaciones para continuar

* **Sin M12, las facturas a aseguradoras quedan con un saldo que nunca se cobra**, lo que ensucia recordatorios y cuentas por cobrar.
* Las respuestas a G1–G5 las dio un dueño de taller que lleva la contabilidad: valen como base, pero la parte fiscal sigue sin
  validar por un contador.
* Un siniestro con deducible mayor a 0 no se factura ni se entrega con el interruptor apagado (decisión A).
* Hay que probar los permisos con cada rol: están repartidos entre recepcionista, jefe de taller y cajero (`CLAUDE.md`, sección 5).

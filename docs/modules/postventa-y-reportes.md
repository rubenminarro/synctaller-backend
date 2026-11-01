# Postventa, reportes y avisos

**COMPLETADOS** según el [criterio del índice](README.md#criterio), salvo WhatsApp. Historia en
[`handoff-03`](../decisions/historial/handoff-03-fases-11-a-25.md).

## Entrega del vehículo (FASE 17)

| Campo | Detalle |
|---|---|
| Objetivo | Registrar la entrega con conformidad |
| Implementado | Comprobante, kilometraje, firma, anulación, controles de factura y deducible |
| Pendiente | Firma en almacenamiento privado (tarea P8) |
| Reglas | `CLAUDE.md` 6.12 y 6.29 |
| Depende de | OT, facturación, pagos, siniestros |
| Tests | `VehicleDelivery` 10 |

## Historial del vehículo (FASE 18)

| Campo | Detalle |
|---|---|
| Objetivo | Consultar la vida del vehículo |
| Implementado | Resumen, visitas, repuestos, kilometraje; costos solo con permiso |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.13 |
| Depende de | Recepción, OT, facturación, entrega |
| Tests | `VehicleHistory` 6 |

## Mantenimiento preventivo (FASE 19)

| Campo | Detalle |
|---|---|
| Objetivo | Anticipar mantenimientos |
| Implementado | Planes por km y días, registro automático desde la OT, km estimado y alertas |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.13 |
| Depende de | Servicios, OT, vehículos |
| Tests | `Maintenance` 7 |

## Reportes y dashboard (FASE 20)

| Campo | Detalle |
|---|---|
| Objetivo | Dar visibilidad del negocio |
| Implementado | Ventas, presupuestos, rentabilidad, productividad, inventario, clientes, cuentas por cobrar y siniestros; dashboard por permisos |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.14 y 6.30 |
| Depende de | Casi todos los módulos, solo lectura |
| Tests | `Report` 8, `ReportExport` 3, `InsurerReports` 4 |

## Notificaciones por email (FASE 23)

| Campo | Detalle |
|---|---|
| Objetivo | Avisar a clientes y al personal |
| Implementado | Avisos por email con registro y reenvío, bandeja interna, recordatorios diarios |
| Pendiente | Avisos a la aseguradora (V7); ver [Aseguradoras](aseguradoras.md) |
| Reglas | `CLAUDE.md` 6.17 |
| Depende de | Presupuestos, recepción, facturación, mantenimiento |
| Tests | `ClientNotification` 6, `InternalNotification` 4, `NotificationReminder` 7 |
| Observaciones | Requiere `queue:work` y `schedule:run`. Envío real sin verificar |

## Exportación y comprobantes (FASE 24)

| Campo | Detalle |
|---|---|
| Objetivo | Descargar reportes y documentos |
| Implementado | PDF de presupuesto, factura, recibo y entrega; reportes en CSV y PDF |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.18 |
| Depende de | Reportes y documentos |
| Tests | `DocumentPdf` 5, `ReportExport` 3 |

## Notificaciones por WhatsApp

| Campo | Detalle |
|---|---|
| Objetivo | Sumar WhatsApp como canal |
| Estado | **PENDIENTE**, sin diseño ni fase asignada |
| Implementado | Nada. `NotificationChannel` solo tiene `MAIL`; `ClientDecisionChannel::WHATSAPP` solo registra por dónde respondió el cliente |

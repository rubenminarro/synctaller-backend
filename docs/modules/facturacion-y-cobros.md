# Facturación y cobros

Todos **COMPLETADOS** según el [criterio del índice](README.md#criterio). Historia en
[`handoff-03`](../decisions/historial/handoff-03-fases-11-a-25.md).

## Servicios y mano de obra (FASE 13)

| Campo | Detalle |
|---|---|
| Objetivo | Catálogo de servicios y reparto del trabajo entre mecánicos |
| Implementado | Catálogo, asignación por porcentaje, horas reales opcionales y comisiones |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.8 |
| Depende de | Mecánicos, OT |
| Tests | `ServiceCatalog` 8, `LaborTime` 6, `WorkOrderCommission` 9 |
| Observaciones | Sin horas, el costo de mano de obra es `null`, nunca cero |

## Cierre económico (FASE 14)

| Campo | Detalle |
|---|---|
| Objetivo | Congelar venta, costos y margen al completar la OT |
| Implementado | `WorkOrderClosing` inmutable, comisiones congeladas y liquidación por período |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.9 |
| Depende de | OT, repuestos en la OT, servicios |
| Tests | `WorkOrderClosing` 7, `ClosingIntegrity` 2 |

## Facturación (FASE 15)

| Campo | Detalle |
|---|---|
| Objetivo | Emitir facturas con timbrado |
| Implementado | Timbrados, numeración, IVA con precios incluidos, contado o crédito, anulación, PDF sin valor fiscal y notas de crédito registradas |
| Pendiente | SIFEN y emisión de notas de crédito: fuera de alcance |
| Reglas | `CLAUDE.md` 6.10 y 6.28 |
| Depende de | Cierre económico, clientes |
| Tests | `InvoiceDraft` 6, `InvoiceIssue` 6, `CreditNote` 6, `WorkOrderSaleBreakdown` 1 |
| Observaciones | La anulación vigente sigue igual hasta responder V19 |

## Pagos (FASE 16)

| Campo | Detalle |
|---|---|
| Objetivo | Registrar cobros |
| Implementado | Pagos parciales, medios de pago, vuelto, recibo para crédito, anulación y resumen de caja |
| Pendiente | Retenciones de IVA (M12) |
| Reglas | `CLAUDE.md` 6.11 |
| Depende de | Facturación |
| Tests | `Payment` 11 |
| Observaciones | Hasta tener M12, las facturas a aseguradoras quedan con un saldo que nunca se cobra |

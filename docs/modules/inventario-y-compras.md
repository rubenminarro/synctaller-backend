# Inventario y compras

Todos **COMPLETADOS** según el [criterio del índice](README.md#criterio). Historia en
[`handoff-03`](../decisions/historial/handoff-03-fases-11-a-25.md); diseño original en
[`handoff-07`](../decisions/historial/handoff-07-roadmap-original.md).

## Catálogo de repuestos y proveedores (FASE 10)

| Campo | Detalle |
|---|---|
| Objetivo | Catálogo de repuestos, categorías y proveedores |
| Implementado | Repuestos con código y SKU, categorías, proveedores y vínculo repuesto–proveedor con `last_cost` |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.6 |
| Depende de | Nada. De él dependen inventario, compras y presupuestos |
| Tests | `Part` 12, `PartSupplier` 7, `Supplier` 4, `PartCategory` 3, `TextValidation` 4 |

## Inventario (FASE 11)

| Campo | Detalle |
|---|---|
| Objetivo | Controlar stock y costo |
| Implementado | Un depósito, stock con reservas, movimientos inmutables, costo promedio ponderado, ajustes por conteo |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.6 |
| Depende de | Catálogo de repuestos |
| Tests | `InventoryEntry` 9, `InventoryExitAndAdjustment` 8, `InventoryQuery` 8 |
| Observaciones | `part_stocks` solo cambia por `InventoryService::applyMovement()` |

## Repuestos en la OT (FASE 12)

| Campo | Detalle |
|---|---|
| Objetivo | Reservar y consumir repuestos en la ejecución |
| Implementado | Reserva, consumo, devolución, extras con aprobación registrada del cliente |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.7 |
| Depende de | OT, inventario |
| Tests | `ExtraPartApproval` 12, `WorkOrderPartFlow` 9, `WorkOrderPartOperations` 8 |

## Compras (FASE 21)

| Campo | Detalle |
|---|---|
| Objetivo | Registrar facturas de proveedores e ingresar stock |
| Implementado | Borrador, recepción, anulación, IVA por línea y actualización de `last_cost` |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.15 |
| Depende de | Proveedores, inventario |
| Tests | `Purchase` 8 |

## Pagos a proveedores (FASE 22)

| Campo | Detalle |
|---|---|
| Objetivo | Registrar egresos y cuentas por pagar |
| Implementado | Pagos parciales, anulación, resumen de egresos, cuentas por pagar |
| Pendiente | Fuera de alcance: retenciones, notas de crédito del proveedor, anticipos y moneda extranjera |
| Reglas | `CLAUDE.md` 6.16 |
| Depende de | Compras |
| Tests | `SupplierPayment` 8 |

# Módulos del taller

Flujo central, desde el cliente hasta la orden de trabajo. Las reglas vigentes están en `CLAUDE.md`, y la historia en
[`handoff-02`](../decisions/historial/handoff-02-modulos-base.md). Todos están **COMPLETADOS** según el
[criterio del índice](README.md#criterio); sus tests pasan en la suite del 2026-11-01.

## Clientes

| Campo | Detalle |
|---|---|
| Objetivo | Registrar personas y empresas |
| Implementado | ABM, tipo `person`/`company`, `display_name`, búsquedas por razón social, baja bloqueada si está en uso |
| Pendiente | Validar el dígito verificador del RUC (V10) |
| Reglas | `CLAUDE.md` 6.1 |
| Depende de | Nada. De él dependen vehículos, recepciones, facturas y aseguradoras |
| Tests | `ClientKind` 9, `ClientVehicle` 7, `Client` 5, `InUseDeletion` 5 |

## Vehículos y su catálogo

| Campo | Detalle |
|---|---|
| Objetivo | Registrar vehículos, marcas y modelos |
| Implementado | Vehículos, también anidados por cliente; placa o chasis; marcas y modelos; bajas bloqueadas si están en uso |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.1 |
| Depende de | Clientes |
| Tests | `Vehicle` 5, `BrandAndVehicleModel` 8 |

## Mecánicos

| Campo | Detalle |
|---|---|
| Objetivo | Ficha del mecánico, vinculada a su usuario |
| Implementado | ABM con costo por hora y comisión; baja bloqueada si tiene OT, asignaciones o diagnósticos |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.1 y 6.8 |
| Depende de | Usuarios |
| Tests | `Mechanic` 5, `DeletedMechanic` 1 |

## Recepción y checklist

| Campo | Detalle |
|---|---|
| Objetivo | Registrar el ingreso del vehículo |
| Implementado | Categorías de servicio, checklist generado, fotos, estado derivado del flujo, cancelación con motivo |
| Pendiente | Fotos en almacenamiento privado (tarea P8) |
| Reglas | `CLAUDE.md` 6.2 |
| Depende de | Clientes, vehículos |
| Tests | `Reception` 13, `ReceptionStatus` 9, `CheckListItem` 3 |

## Diagnóstico y su catálogo

| Campo | Detalle |
|---|---|
| Objetivo | Registrar la revisión técnica |
| Implementado | Diagnóstico con ítems y fotos; catálogo reutilizable, del que sale siempre cada ítem |
| Pendiente | Fotos en almacenamiento privado (tarea P8) |
| Reglas | `CLAUDE.md` 6.3 |
| Depende de | Recepción, mecánicos |
| Tests | `Diagnostic` 11, `DiagnosticItemTemplate` 6 |
| Observaciones | Fotos con permisos `diagnostic-item-photos.*` |

## Presupuestos

| Campo | Detalle |
|---|---|
| Objetivo | Cotizar y registrar la respuesta del cliente |
| Implementado | Estados, ítems, IVA, PDF, respuesta inmutable del cliente, responsable por línea, re-aceptación |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.4 y 6.26 |
| Depende de | Recepción, diagnóstico, servicios, repuestos |
| Tests | `BudgetLifecycle` 8, `BudgetClientAcceptance` 6, `BudgetAmountRange` 5, `BudgetItemResponsibility` 4, `BudgetSend` 3, `BudgetItemPermission` 1 |
| Observaciones | El presupuesto no guarda la tasa de IVA: el PDF la deduce de sus importes (`Budget::taxRate`) |

## Órdenes de trabajo

| Campo | Detalle |
|---|---|
| Objetivo | Ejecutar lo aprobado |
| Implementado | Creación desde el presupuesto, estados, ítems, responsable, bloqueo por autorización en siniestros |
| Pendiente | Nada conocido |
| Reglas | `CLAUDE.md` 6.5 |
| Depende de | Presupuestos, mecánicos. De ella dependen repuestos, cierre, facturación y mantenimiento |
| Tests | `WorkOrderLifecycle` 9, `ClosingIntegrity` 2 |
| Observaciones | La OT entregada sigue `completed`: no hay estado de entrega |

Además, `DocumentSequence` (6 tests) cubre los códigos internos que usan estos módulos.

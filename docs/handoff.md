# Project Handoff Document

## SyncTaller (antes Presupuestador) — Sistema de Gestión para Taller Mecánico

**Versión del handoff:** 1.2
**Fecha:** 2026-10-17
**Estado:** Backend/API funcional — roadmap original completo (FASES 10 a 20: repuestos, inventario, servicios,
cierre económico, facturación, pagos, entrega, historial, mantenimiento preventivo, reportes), FASE 21 (compras) y
FASE 22 (pagos a proveedores), FASE 23 (notificaciones), FASE 24 (exportación y comprobantes), FASE 25 (auditoría); 6 roles;
374 tests en verde (2026-10-24)
**Stack principal:** Laravel 13.38 (desde el 2026-10-24; antes 12.70) + MySQL (8.4 en Laragon). Frontend previsto en React 19: **no está en este
repositorio** (ver 1.2)
**Entorno local:** Laragon / Windows
**Base de datos:** `synctaller` (hasta el 2026-10-21, `presupuestador`)

> **Propósito de este documento (desde la v1.2).** Es el registro histórico y de decisiones del proyecto: contexto,
> diseño por fase, decisiones de negocio y roadmap original. Hasta la v1.1 estaba en `CLAUDE.md`; en la v1.2 se movió
> aquí (con `git mv`, sin perder contenido) y `CLAUDE.md` pasó a contener solo las instrucciones operativas vigentes.
>
> * Instrucciones para trabajar en el código: [`CLAUDE.md`](../CLAUDE.md).
> * Instalación y uso: [`README.md`](../README.md).
> * Pruebas manuales por fase: [`docs/testing/guia-postman.md`](testing/guia-postman.md).
> * Discrepancias conocidas entre código y documentación: sección 14.
>
> Las secciones 5 y 6 (roadmap original) y 8 (checklist de migración) se conservan como historia.

---

# 1. Visión general y arquitectura

## 1.1 Propósito del proyecto

El proyecto **SyncTaller** (llamado **Presupuestador** hasta el 2026-10-21) es un sistema de gestión para un taller
mecánico.

Su objetivo es cubrir progresivamente el flujo completo de atención de un vehículo:

```text
Cliente
   ↓
Vehículo
   ↓
Recepción
   ↓
Diagnóstico
   ↓
Presupuesto
   ↓
Aprobación del presupuesto
   ↓
Orden de Trabajo
   ↓
Ejecución de trabajos
   ↓
Repuestos / materiales
   ↓
Finalización
   ↓
Entrega del vehículo
```

El proyecto comenzó con foco en la generación y gestión de presupuestos, pero evolucionó hacia un sistema más completo de gestión operativa de taller.

La arquitectura debe permitir continuar agregando módulos sin romper los módulos existentes.

---

# 1.2 Stack tecnológico

## Backend

* PHP 8.3
* Laravel 13 (hasta el 2026-10-24, Laravel 12; ver 2.51)
* Laravel Sanctum
* Laravel API Resources
* Laravel Form Requests
* Eloquent ORM
* MySQL
* Spatie Laravel Permission

## Frontend

* React 19 (previsto)
* Consumo de API REST
* Autenticación mediante Sanctum

> **Verificado el 2026-10-17:** este repositorio no contiene código React. `package.json` es el scaffold por defecto
> de Laravel (Vite 7, Tailwind CSS 4, axios) sin uso real (`resources/views/welcome.blade.php` y `resources/js/app.js`
> sin cambios; `routes/web.php` sin rutas). Lo único preparado para un SPA es `config/cors.php` y
> `SANCTUM_STATEFUL_DOMAINS` con `http://localhost:5173` (puerto por defecto de Vite). La versión de React, la
> biblioteca visual y el repositorio del frontend no pudieron verificarse.

## Entorno de desarrollo

* Windows
* Laragon
* MySQL
* Composer
* Node.js / npm
* Git

---

# 1.3 Arquitectura general

El backend utiliza una arquitectura orientada a:

```text
Controller
    ↓
Form Request
    ↓
Service
    ↓
Model / Eloquent
    ↓
Database
```

Los Controllers son responsables principalmente de:

* recibir requests;
* autorizar operaciones;
* invocar Services;
* devolver Resources;
* devolver respuestas API estandarizadas.

La lógica de negocio importante debe permanecer en los Services y no ser trasladada directamente a los Controllers.

---

# 1.4 Principales capas

## Controllers

Ubicación:

```text
app/Http/Controllers/Api/
```

Responsabilidades:

* endpoints HTTP;
* autorización;
* recepción de requests;
* llamada a Services;
* transformación mediante Resources;
* respuesta API.

No deben contener lógica de negocio compleja.

---

## Requests

Ubicación:

```text
app/Http/Requests/
```

Responsabilidades:

* validación;
* reglas de entrada;
* mensajes de validación.

Cuando ya existe un Request específico para una operación, debe reutilizarse en lugar de duplicar reglas dentro del Controller.

---

## Services

Ubicación:

```text
app/Services/
```

Contienen reglas de negocio.

Ejemplos:

```text
BudgetService
WorkOrderService
```

Los Services son particularmente importantes para:

* cambios de estado;
* validaciones de negocio;
* transacciones;
* creación de entidades relacionadas;
* reglas que involucran múltiples modelos.

---

## Models

Ubicación:

```text
app/Models/
```

Representan entidades persistentes y sus relaciones Eloquent.

---

## Resources

Ubicación:

```text
app/Http/Resources/
```

Responsables de controlar el formato JSON público de la API.

Existe una diferenciación entre:

* Resource para listados;
* ShowResource para detalle.

Ejemplo:

```text
BudgetResource
ShowBudgetResource
WorkOrderResource
ShowWorkOrderResource
```

---

## Exceptions

Ubicación:

```text
app/Exceptions/
```

Actualmente existe una jerarquía orientada a errores de dominio (una excepción por módulo):

```text
AppException
├── BudgetException
├── WorkOrderException
├── WorkOrderPartException
├── PartException
├── SupplierException
├── InventoryException
├── ServiceException
├── InvoiceException
├── PaymentException
└── VehicleDeliveryException
```

---

## Enums

Ubicación:

```text
app/Enums/
```

Los estados y tipos importantes se representan mediante Enums PHP.

Esto evita trabajar indiscriminadamente con strings dispersos por el código.

---

## Traits

Ubicación:

```text
app/Traits/
```

Principalmente:

```text
ApiResponse
```

Este Trait centraliza las respuestas JSON estándar.

---

# 1.5 Estructura sugerida

La estructura lógica del backend debe mantenerse aproximadamente así:

```text
app/
├── Enums/
│   ├── BudgetStatus.php
│   ├── BudgetItemType.php
│   ├── WorkOrderStatus.php
│   ├── WorkOrderItemStatus.php
│   ├── WorkOrderItemType.php
│   └── ...
│
├── Exceptions/
│   ├── AppException.php
│   ├── BudgetException.php
│   ├── WorkOrderException.php
│   └── ...                    (una por módulo: ver 1.4)
│
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       ├── AuthController.php
│   │       ├── BudgetController.php
│   │       ├── WorkOrderController.php
│   │       ├── WorkOrderItemController.php
│   │       ├── VehicleController.php
│   │       └── ...
│   │
│   ├── Requests/
│   │   ├── LoginRequest.php
│   │   ├── StoreBudgetRequest.php
│   │   ├── UpdateBudgetRequest.php
│   │   ├── StoreWorkOrderRequest.php
│   │   ├── UpdateWorkOrderRequest.php
│   │   └── ...
│   │
│   └── Resources/
│       ├── BudgetResource.php
│       ├── ShowBudgetResource.php
│       ├── WorkOrderResource.php
│       ├── ShowWorkOrderResource.php
│       ├── WorkOrderItemResource.php
│       └── ...
│
├── Models/
│   ├── User.php
│   ├── Client.php
│   ├── Vehicle.php
│   ├── Reception.php
│   ├── Diagnostic.php
│   ├── Budget.php
│   ├── BudgetItem.php
│   ├── WorkOrder.php
│   ├── WorkOrderItem.php
│   ├── Mechanic.php
│   └── ...
│
├── Services/
│   ├── BudgetService.php
│   ├── WorkOrderService.php
│   └── ...
│
└── Traits/
    └── ApiResponse.php
```

---

# 2. Estado actual

## 2.1 Estado global

Las siguientes áreas ya fueron desarrolladas y validadas durante el proyecto:

* autenticación;
* usuarios/roles/permisos;
* clientes;
* vehículos;
* recepciones;
* diagnóstico;
* categorías de servicios;
* presupuestos;
* items de presupuesto;
* órdenes de trabajo;
* items de órdenes de trabajo;
* mecánicos;
* flujo de estados;
* respuestas API;
* excepciones;
* códigos internos de error;
* estandarización de códigos HTTP;
* auditoría de Controllers;
* auditoría de manejo de excepciones.

La **FASE 9.14 — Auditoría de excepciones y Controllers** fue cerrada.

También se completaron:

* **FASE 10 — Catálogo de repuestos:** `Part`, `PartCategory`, `Supplier`, pivote `part_supplier`.
* **FASE 11 — Inventario:** ver sección 2.24.
* **FASE 12 — Repuestos en WorkOrder:** ver sección 2.25.
* **FASE 13 — Servicios y mano de obra (comisiones):** ver sección 2.26.
* **FASE 14 — Cierre económico de WorkOrder:** ver sección 2.27.
* **FASE 15 — Facturación:** ver sección 2.28.
* **FASE 16 — Pagos:** ver sección 2.29.
* **FASE 17 — Entrega del vehículo:** ver sección 2.30.
* **FASE 18 — Historial del vehículo:** ver sección 2.31.
* **FASE 19 — Mantenimiento preventivo:** ver sección 2.32.
* **FASE 20 — Reportes y dashboard:** ver sección 2.33.
* **FASE 21 — Compras:** ver sección 2.34.
* **FASE 22 — Pagos a proveedores:** ver sección 2.35.
* **FASE 23 — Notificaciones:** ver sección 2.36.
* **FASE 24 — Exportación de reportes y comprobantes imprimibles:** ver sección 2.37.
* **FASE 25 — Auditoría avanzada:** ver sección 2.38.

---

# 2.24 Inventario (FASE 11)

Componentes:

```text
Warehouse              (un único depósito "DEP-01 Principal", creado por migración, sin API)
PartStock              (saldo por repuesto/depósito: on_hand, reserved, average_cost)
InventoryMovement      (historial inmutable, fuente de verdad del stock)
InventoryMovementType  (initial, purchase, adjustment_in/out, supplier_return, loss,
                        work_order_out, work_order_return; purchase_cancellation desde la FASE 21)
InventoryService       (registerEntry, registerExit, adjust, reserve, release)
InventoryException
App\Support\Decimal    (redondeo BCMath)
```

Reglas:

* Todo cambio de stock pasa por `InventoryService::applyMovement()` bajo `lockForUpdate()` sobre `part_stocks`.
* `quantity` del movimiento tiene signo (+ entrada / − salida); `balance_after` = saldo acumulado.
* Los movimientos no se editan ni eliminan (`INVENTORY_MOVEMENT_IMMUTABLE`); se corrigen con ajustes.
* Costo promedio ponderado (4 decimales), recalculado solo en entradas. `parts.cost_price` es solo referencia.
* Cálculos con BCMath (strings), nunca con float.
* Sin stock negativo: salidas contra disponible (`on_hand − reserved`) → `INSUFFICIENT_STOCK`.
* Ajuste = conteo físico (`counted_quantity`); el sistema genera la diferencia.
* Descontinuado: no admite entradas (`PART_DISCONTINUED_NO_ENTRY`); sí salidas y ajustes.
* Unidades `unit`/`set`/`kit` no admiten fracciones (`INVENTORY_FRACTIONAL_QUANTITY_NOT_ALLOWED`).
* Un repuesto con stock ≠ 0 no puede eliminarse (`PART_HAS_STOCK`).
* `reserve()`/`release()` no tienen endpoint propio: los usa la FASE 12 (`WorkOrderPartService`).
* Cantidades de inventario en `decimal(12,3)`; `budget_items`/`work_order_items` siguen en `(10,2)`.

Endpoints: `GET inventory/stocks`, `GET inventory/movements[/{id}]`, `GET parts/{part}/movements`,
`POST inventory/entries|exits|adjustments`. Permisos: `inventory.index|show|entry|exit|adjust`
(mecánico: solo `index` y `show`).

> **Desde la FASE 21 (2.34):** `POST inventory/entries` solo registra el stock inicial; las compras entran por
> `POST purchases/{purchase}/receive`.

Tests: los tests usan SQLite en memoria; si en el `php.ini` de Laragon `pdo_sqlite` está deshabilitado (pasaba en
una de las máquinas de desarrollo; en otra ya está habilitado y alcanza con `vendor/bin/phpunit`), ejecutar con:

```bash
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit
```

---

# 2.25 Repuestos en WorkOrder (FASE 12)

Componentes:

```text
budget_items.part_id    (nullable; solo type = part; vínculo con el catálogo)
WorkOrderPart           (repuesto realmente usado en la OT; work_order_item_id nullable = extra no presupuestado)
WorkOrderPartStatus     (pending, reserved, consumed, returned, cancelled; awaiting_approval desde el 2026-10-23, ver 2.49)
WorkOrderPartService    (createFromBudgetItem, add, update, reserve, consume, returnToStock, cancel,
                         cancelOpenLines, ensureResolved)
WorkOrderPartException
InventoryService        (+ consumeReservation, returnToStock, normalizeQuantity pública)
```

Ciclo de una línea:

```text
creación con stock        → reserved   (reserve)
creación sin stock        → pending
pending   --reserve-->      reserved
pending/reserved --consume--> consumed  (libera reserva + salida work_order_out por lo usado)
pending/reserved --cancel-->  cancelled (libera reserva)
consumed  --return-->       consumed / returned si neto = 0 (entrada work_order_return al unit_cost de la línea)
```

Reglas:

* `createFromBudget` crea una línea por cada BudgetItem `part` con `part_id` e intenta reservar; si falta stock la OT se crea igual y la línea queda `pending`.
* Agregar/editar/reservar/cancelar: OT `pending|in_progress|paused`. Consumir/devolver: solo `in_progress`.
* OT `completed`/`cancelled`: líneas congeladas (`WORK_ORDER_PARTS_NOT_EDITABLE`).
* Cancelar la OT o un WorkOrderItem cancela sus líneas abiertas y libera reservas.
* Completar la OT exige que no haya líneas `pending|reserved` (`WORK_ORDER_HAS_UNRESOLVED_PARTS`).
* Un consumo por línea; lo usado puede diferir de lo reservado (excedente contra disponible).
* `unit_cost` se copia del movimiento de consumo; `unit_price` del BudgetItem o del `sale_price` del catálogo.
* Totales (`total_cost`, `total_price`) se calculan sobre la cantidad neta (consumida − devuelta); no se persisten.
* Repuestos `inactive` no se presupuestan ni se agregan (`PART_NOT_USABLE`); descontinuados sí.
* `InventoryMovement.reference_type = 'work_order_part'` (morphMap sin enforce en AppServiceProvider).
* Orden de bloqueo: OT → línea → `part_stocks`.

Endpoints: `GET|POST work-orders/{workOrder}/parts`, `PATCH work-orders/{workOrder}/parts/{part}`,
`POST work-orders/{workOrder}/parts/{part}/reserve|consume|return|cancel`.
Permisos: `work-order-parts.index|store|update|reserve|consume|return|cancel`
(mecánico: `index`, `consume`, `return`).

---

# 2.26 Servicios y mano de obra (FASE 13)

Contexto del negocio: los mecánicos cobran **sueldo + comisión**, un trabajo puede hacerlo **más de un mecánico**
y la mano de obra se cobra con **lista de precios**. Hoy **el taller no registra horas**: por eso el tiempo estimado,
las horas reales y el costo del mecánico (13.4–13.6) son **opcionales**. Sin horas, el costo de mano de obra es
desconocido (`null`), nunca cero.

Tipos de ítem:

```text
service  → trabajo del catálogo (precio de lista, ajustable)      → genera comisión
labor    → mano de obra fuera de catálogo, cotizada a mano        → genera comisión
part     → repuesto (FASE 12)                                     → no genera comisión
```

Componentes:

```text
Service                   (catálogo: code SRV-XXXXXX, service_category_id, name, description, standard_price,
                           estimated_hours nullable, status)
ServiceStatus             (active, inactive)
ServiceCategory           (existente; reutilizada como categoría del catálogo; API solo lectura)
budget_items.service_id   (nullable; solo type = service; sin unit_price toma standard_price)
budget_items.estimated_hours (nullable; service: catálogo × cantidad si no se informa; labor: opcional; part: prohibido)
WorkOrderItemMechanic     (work_order_item_mechanics: mecánico, share_percentage, commission_percentage copiado,
                           hours nullable, hour_cost nullable copiado de Mechanic.hour_cost)
ServiceCatalogService     (create, update, activate, deactivate, delete)
WorkOrderCommissionService (assignResponsible, syncItemMechanics, reassignResponsible, logHours,
                            summary, laborSummary, mechanicCommissions)
ServiceException
```

Reglas:

* Al crear la OT, cada ítem `labor`/`service` queda asignado al mecánico responsable con 100%.
* Cambiar el responsable de una OT `pending` reasigna los trabajos que tenía al 100%.
* `PUT .../items/{item}/mechanics` reemplaza la asignación completa: sin porcentajes → partes iguales
  (33.33/33.33/33.34); con porcentajes → todos deben informarlo y sumar 100 (`WORK_ORDER_ITEM_SHARES_INVALID`).
* Solo mecánicos `active` (`MECHANIC_NOT_ACTIVE`); solo ítems `labor`/`service` (`WORK_ORDER_ITEM_NOT_COMMISSIONABLE`).
* No editable con la OT `completed`/`cancelled` ni con el ítem `cancelled` (`WORK_ORDER_ITEM_MECHANICS_NOT_EDITABLE`).
* `commission_percentage` se copia al asignar; un mecánico que sigue asignado conserva su copia.
* Comisión = `budget_item.total` (sin IVA) × `share_percentage` × `commission_percentage`. Se calcula, no se persiste
  (la FASE 14 la congela). Los ítems cancelados no generan comisión.
* Reporte liquidable: ítems `completed` de OT `completed`, filtrado por `completed_at` (por defecto, el mes en curso).
  *(Desde la FASE 14 el reporte lee las comisiones congeladas en `work_order_commissions`, filtradas por `earned_at`;
  ver 2.27.)*
  Sin `mechanic-commissions.view-all` cada mecánico ve solo las propias (403 `FORBIDDEN`).
* Servicio inactivo no se presupuesta (`SERVICE_NOT_USABLE`); usado en presupuestos no se elimina (`SERVICE_IN_USE`).
* Horas reales: `PATCH .../items/{item}/mechanics/{mechanic}/hours` (`hours` o `null` para borrar). Solo con la OT
  `in_progress`/`paused` y el ítem no cancelado (`WORK_ORDER_ITEM_HOURS_NOT_EDITABLE`). Mecánico no asignado → 404
  (scoped binding vía `WorkOrderItem::mechanics()`). Sin `log-hours-any` cada mecánico carga solo las propias.
* `hour_cost` se copia en la primera carga y se conserva; si `Mechanic.hour_cost` es 0 queda `null` (costo desconocido).
* Costo de mano de obra = `hours × hour_cost`; completar la OT no exige horas.

Endpoints: `GET service-categories`, CRUD `services` (+ `activate`/`deactivate`),
`GET|PUT work-orders/{workOrder}/items/{item}/mechanics`,
`PATCH work-orders/{workOrder}/items/{item}/mechanics/{mechanic}/hours`,
`GET mechanics/{mechanic}/commissions?date_from&date_to`.
`ShowWorkOrderResource` incluye `items[].estimated_hours`, `items[].mechanics` (con `hours`, `hour_cost`, `labor_cost`),
`commission_summary` (`is_final`, `total_commission`, `by_mechanic`) y `labor_summary` (`estimated_hours`,
`actual_hours`, `labor_cost`, `assignments_without_hours`, `assignments_without_cost`, `is_complete`).

Permisos: `service-categories.index`, `services.index|store|show|update|destroy|change-status`,
`work-order-item-mechanics.index|sync|log-hours|log-hours-any`, `mechanic-commissions.index|view-all`
(mecánico: `service-categories.index`, `services.index|show`, `work-order-item-mechanics.index|log-hours`,
`mechanic-commissions.index`).

---

# 2.27 Cierre económico de WorkOrder (FASE 14)

Componentes:

```text
WorkOrderClosing        (work_order_closings: 1 por OT; presupuestado, venta, costos, margen, is_cost_complete)
WorkOrderCommission     (work_order_commissions: comisión congelada por mecánico y trabajo;
                         earned_at = completed_at de la OT; settled_at/settled_by = liquidación)
WorkOrderClosingService (close, summary)
WorkOrderCommissionService (+ freeze, settle; mechanicCommissions lee las comisiones congeladas)
```

Reglas (importes sin IVA):

* `WorkOrderService::complete()` cierra la OT en la misma transacción: congela el cierre y las comisiones.
* Venta = mano de obra/servicios no cancelados (total del BudgetItem) + repuestos **usados** (neto × `unit_price`,
  incluidos los extras no presupuestados) + repuestos fuera de catálogo no cancelados (total presupuestado).
* Costo = repuestos (neto × `unit_cost`) + mano de obra conocida (horas × `hour_cost`) + comisiones.
* Si falta algún costo (horas sin cargar, mecánico sin `hour_cost`, repuesto fuera de catálogo) el margen se calcula
  con los costos conocidos y `is_cost_complete = false`. `cost.labor` es `null` si no hay ninguna hora cargada.
* `variance` = venta real − presupuestado. `margin_percentage` = margen / venta × 100 (`null` si la venta es 0).
* OT abierta: `economic_summary` se calcula a la fecha (`is_final = false`). OT completada: se lee del cierre congelado.
* `economic_summary` solo se incluye con `work-orders.view-economics` (el mecánico no ve costos ni margen).
* Comisiones: el reporte lee `work_order_commissions` (`?status=pending|settled`, `total_pending`, `total_settled`).
  `POST mechanics/{mechanic}/commissions/settle` (`date_from`, `date_to` obligatorios) liquida las pendientes del
  período; sin pendientes → `MECHANIC_COMMISSIONS_NOTHING_TO_SETTLE`.
* `WorkOrderClosing` es inmutable (`WORK_ORDER_CLOSING_IMMUTABLE`): es la base de la facturación.
* La migración 000037 crea solo la estructura. Regla: **las migraciones no llaman a código de la aplicación**
  (Services/Models), porque ese código evoluciona con el esquema y rompería `migrate:fresh` en el futuro.

Endpoints: `GET work-orders/{workOrder}` (`economic_summary`), `GET mechanics/{mechanic}/commissions`,
`POST mechanics/{mechanic}/commissions/settle`.
Permisos: `work-orders.view-economics`, `mechanic-commissions.settle` (solo administrador).

---

# 2.28 Facturación (FASE 15)

Contexto (Paraguay): **timbrado** con numeración `001-001-0000001` (establecimiento − punto de expedición −
correlativo), IVA **10% / 5% / exento**, condición **contado/crédito**. Los precios del sistema son **sin IVA**;
la factura los expresa **con IVA incluido**. Importes en **guaraníes enteros** (`config/invoice.php`: `decimals = 0`,
`default_tax_rate = 10`).

Componentes:

```text
InvoiceStamp        (invoice_stamps: stamp_number, establishment, expedition_point, valid_from, valid_until,
                     next_number, is_active; solo uno activo por establecimiento + punto)
Invoice             (invoices: work_order_id, client_id, status, número y copia del timbrado al emitir, datos del
                     cliente copiados, condition, due_date, totales por tasa, IVA 5/10, descuento, total)
InvoiceItem         (invoice_items: source morph work_order_item | work_order_part, quantity, base_unit_price y
                     base_total sin IVA, tax_rate, unit_price con IVA, discount, total)
InvoiceStatus       (draft, issued, cancelled)   InvoiceCondition (cash, credit)   TaxRate (0, 5, 10)
InvoiceService      (generate, update, updateItem, issue, cancel, delete)
InvoiceStampService (create, update)
InvoiceException
```

Ciclo:

```text
OT completed --generar-->  draft    (líneas = lo realmente usado; debe coincidir con closing.sale_total)
draft        --editar-->   draft    (cliente, condición, vencimiento; por línea: descripción, tasa, descuento)
draft        --descartar-> (se elimina; no consume número)
draft        --emitir-->   issued   (timbrado activo y vigente; toma next_number con lockForUpdate; inmutable)
issued       --anular-->   cancelled (motivo obligatorio; el número no se reutiliza; la OT puede refacturarse)
```

Reglas:

* Líneas: `labor`/`service` no cancelados y repuestos fuera de catálogo (valores presupuestados), repuestos de
  catálogo por cantidad neta usada (`WorkOrderPart`, incluidos extras). Si no coincide con el cierre:
  `INVOICE_TOTAL_MISMATCH`.
* Precio con IVA = sin IVA × (100 + tasa) / 100, redondeado; total = cantidad × precio − descuento (con IVA).
  Liquidación: IVA 10% = total_10 ÷ 11, IVA 5% = total_5 ÷ 21. Descuento solo por línea, ≤ importe de la línea.
* Una factura vigente (`draft`/`issued`) por OT (`WORK_ORDER_ALREADY_INVOICED`); OT no completada:
  `WORK_ORDER_NOT_INVOICEABLE`.
* Emitir exige RUC/CI (`INVOICE_CUSTOMER_TAX_ID_REQUIRED`) y, a crédito, vencimiento ≥ hoy (`INVOICE_DUE_DATE_REQUIRED`).
* Timbrado: sin vigente → `INVOICE_STAMP_NOT_AVAILABLE`; varios vigentes sin `invoice_stamp_id` →
  `INVOICE_STAMP_REQUIRED`; numeración agotada (> 9.999.999) → `INVOICE_STAMP_EXHAUSTED`; dos activos en el mismo
  establecimiento + punto → `INVOICE_STAMP_OVERLAP`.
* `Invoice` emitida es inmutable a nivel de modelo (solo admite los campos de anulación): `INVOICE_NOT_EDITABLE`.
* `ShowWorkOrderResource.invoice` (factura vigente) solo con `invoices.show`.
* Fuera de alcance: SIFEN (factura electrónica), notas de crédito, venta de mostrador sin OT, PDF, pagos (FASE 16).

Endpoints: `GET|POST invoice-stamps`, `PATCH invoice-stamps/{invoiceStamp}`, `POST work-orders/{workOrder}/invoice`,
`GET invoices`, `GET|PATCH|DELETE invoices/{invoice}`, `PATCH invoices/{invoice}/items/{item}`,
`POST invoices/{invoice}/issue|cancel`.
Permisos (solo administrador): `invoice-stamps.index|store|update`,
`invoices.index|show|store|update|issue|cancel|destroy`.

---

# 2.29 Pagos (FASE 16)

Componentes:

```text
Payment               (payments: invoice_id, payment_date, amount, method, reference, bank, notes, status,
                       receipt_sequence/receipt_number, created_by, datos de anulación)
PaymentMethod         (cash, card, transfer, check)
PaymentStatus         (registered, cancelled)
InvoicePaymentStatus  (unpaid, partial, paid; calculado, no se persiste)
PaymentService        (register, cancel, summary)
PaymentException
Invoice               (+ payments, paidTotal, balance, paymentStatus)
```

Ciclo:

```text
factura issued --registrar--> registered   (no supera el saldo; bloquea la factura)
registered     --anular-->    cancelled    (motivo obligatorio; el saldo vuelve a estar disponible)
```

Reglas:

* Solo facturas `issued` admiten pagos (`INVOICE_NOT_PAYABLE`). Varios pagos por factura, al contado o a crédito,
  hasta cubrir el total. Saldo = total − pagos `registered`.
* Monto en guaraníes enteros (`integer`, > 0). Supera el saldo → `PAYMENT_EXCEEDS_BALANCE`; saldo 0 → `INVOICE_ALREADY_PAID`.
* Vuelto (solo efectivo): `amount_received` opcional, ≥ `amount`; se guarda `change_amount = amount_received − amount`
  (migración 000042). `amount` es lo aplicado a la factura; sin `amount_received` el pago es exacto (ambos `null`).
  En otros medios `amount_received` está prohibido.
* `payment_date` opcional (hoy por defecto), no futura (validación) ni anterior a `issue_date` (`PAYMENT_DATE_BEFORE_ISSUE`).
* `reference` obligatoria para tarjeta, transferencia y cheque (se descarta en efectivo); `bank` obligatorio en cheque
  (se guarda solo en cheque y transferencia).
* Recibo `REC-000001` correlativo solo para pagos de facturas a crédito (`receipt_sequence` único); un recibo anulado
  no se reutiliza. Al contado, la factura es el comprobante.
* El pago no se edita ni se elimina (`PAYMENT_NOT_EDITABLE`, a nivel de modelo); anular uno ya anulado →
  `PAYMENT_NOT_CANCELLABLE`.
* Una factura con pagos vigentes no se anula (`INVOICE_HAS_PAYMENTS`): primero se anulan los pagos.
* `ShowInvoiceResource` incluye `payment_summary` (`paid_total`, `balance`, `status`) y `payments`; el listado de
  facturas incluye `balance` y `payment_status` (con `withSum`).
* Resumen de caja: pagos `registered` del período (por defecto, hoy) agrupados por medio, `cash.received` y
  `cash.change` (efectivo recibido y vuelto entregado), más la cantidad de anulados.

Endpoints: `POST invoices/{invoice}/payments`, `GET payments`, `GET payments/summary?date_from&date_to`,
`GET payments/{payment}`, `POST payments/{payment}/cancel`.
Permisos (solo administrador): `payments.index|show|store|cancel|summary`.

---

# 2.30 Entrega del vehículo (FASE 17)

Componentes:

```text
VehicleDelivery         (vehicle_deliveries: reception_id, vehicle_id, client_id, delivery_number ENT-000001,
                         delivered_at/by, final_mileage, fuel_level, received_by_name/document, customer_accepted,
                         signature_path (imagen opcional), observations, previous_reception_status, datos de anulación)
VehicleDeliveryStatus   (delivered, cancelled)
VehicleDeliveryService  (deliver, cancel)
VehicleDeliveryException
Reception               (+ deliveries)
```

Ciclo:

```text
recepción --entregar--> delivered   (recepción → delivered; vehicles.mileage solo avanza)
delivered --anular-->   cancelled   (motivo; la recepción vuelve a previous_reception_status; ENT no se reutiliza)
```

Reglas:

* Una entrega vigente por recepción (`DELIVERY_ALREADY_REGISTERED`, 409); recepción cancelada → `DELIVERY_RECEPTION_CANCELLED`.
* Ninguna OT de la recepción abierta (`pending|in_progress|paused` → `DELIVERY_WORK_ORDERS_OPEN`) y al
  menos una completada (`DELIVERY_WITHOUT_COMPLETED_WORK_ORDER`).
* Cada OT completada con factura vigente **emitida** (`DELIVERY_WORK_ORDER_NOT_INVOICED`); si es al contado, sin saldo
  (`DELIVERY_INVOICE_NOT_PAID`). A crédito se entrega con saldo.
* `final_mileage` ≥ kilometraje de la recepción (`DELIVERY_MILEAGE_BELOW_RECEPTION`). `customer_accepted` debe ser `true`
  (validación). Quién retira (nombre y documento) es obligatorio; la firma es una imagen opcional (disco `public`).
* La OT no cambia de estado al entregar (sigue `completed`; `WorkOrderStatus` no tiene estado de entrega), porque el cierre
  económico y la facturación dependen de `completed`.
* La entrega no se edita ni se elimina (`DELIVERY_NOT_EDITABLE`, a nivel de modelo); anular una anulada →
  `DELIVERY_NOT_CANCELLABLE`. Anular no revierte el kilometraje del vehículo.
* *(2026-10-19)* Si anular reabriría la recepción y el vehículo ya reingresó con otra recepción abierta, la anulación
  se rechaza (`DELIVERY_VEHICLE_HAS_OPEN_RECEPTION`, 409): un vehículo nunca tiene dos recepciones abiertas.

Endpoints: `POST receptions/{reception}/delivery` (JSON o multipart con `signature`), `GET vehicle-deliveries`,
`GET vehicle-deliveries/{delivery}`, `POST vehicle-deliveries/{delivery}/cancel`.
Permisos (solo administrador): `vehicle-deliveries.index|show|store|cancel`.

---

# 2.31 Historial del vehículo (FASE 18)

Solo lectura y **sin tablas nuevas**: `VehicleHistoryService` reúne lo que ya existe por vehículo.

```text
VehicleHistoryService           (summary, timeline, parts, mileage)
VehicleHistoryController        (show, parts, mileage)
VehicleHistoryReceptionResource (una visita del timeline)
VehicleHistoryPartResource
```

Contenido:

* `summary`: visitas (recepciones), primera y última visita, último kilometraje (última lectura o `vehicles.mileage`),
  OT completadas, último servicio, total facturado (facturas emitidas) y saldo pendiente. Con
  `work-orders.view-economics` agrega `economics` (venta, costo y margen de los cierres).
* `timeline` (paginado, visita más reciente primero): recepción (fecha, km de ingreso, problema, categorías),
  diagnósticos con hallazgos, presupuestos, OT (trabajos, repuestos usados con cantidad neta, factura con saldo y
  estado de cobro, `economics` solo con el permiso) y entrega vigente (km de salida).
* `parts`: líneas de repuestos con cantidad neta > 0 (precio; costo solo con el permiso).
* `mileage`: lecturas de recepciones y entregas vigentes en orden cronológico (mismo día: por hora de registro);
  `decreased = true` si una lectura es menor que la anterior (posible error de carga).

Nota técnica: `$this->when()` no se resuelve dentro de colecciones anidadas de un Resource; para claves condicionales
anidadas (como `economics` por OT) se arma el array a mano.

Endpoints: `GET vehicles/{vehicle}/history?per_page`, `GET vehicles/{vehicle}/history/parts?per_page`,
`GET vehicles/{vehicle}/history/mileage`.
Permiso: `vehicle-history.view` (administrador, mecánico, recepcionista, jefe-taller, cajero y depósito).

---

# 2.32 Mantenimiento preventivo (FASE 19)

Componentes:

```text
MaintenancePlan     (maintenance_plans: name, description, service_id nullable, interval_km, interval_days, is_active)
VehicleMaintenance  (vehicle_maintenances: vehicle_id + maintenance_plan_id únicos, interval_km/interval_days propios
                     (null = los del plan), last_done_at, last_done_mileage, work_order_id, next_due_at, next_due_mileage)
MaintenanceStatus   (unknown, ok, due_soon, overdue; calculado)
MaintenanceService  (createPlan, updatePlan, deletePlan, recordDone, registerFromWorkOrder, configure,
                     estimatedMileage, forVehicle, alerts)
MaintenanceException
config/maintenance.php (due_soon_km = 1000, due_soon_days = 30)
MaintenancePlanSeeder  (aceite 5.000 km/180 días, rotación 10.000 km, alineación 10.000 km/365 días)
```

Reglas:

* Un plan tiene intervalo en km y/o días (al menos uno: validación al crear, `MAINTENANCE_INTERVAL_REQUIRED` al editar).
  Con seguimiento no se elimina (`MAINTENANCE_PLAN_IN_USE`, 409): se desactiva.
* Registro **automático**: `WorkOrderService::complete()` llama a `registerFromWorkOrder()`; cada ítem `service`
  completado cuyo `service_id` es el de un plan activo registra el mantenimiento con la fecha de cierre y el km de la
  recepción. Un registro más viejo que el último se ignora.
* Registro **manual** (`POST vehicles/{vehicle}/maintenances`): plan activo (`MAINTENANCE_PLAN_INACTIVE`), fecha no
  futura, no anterior al último (`MAINTENANCE_DONE_OUT_OF_ORDER`) ni con menos km (`MAINTENANCE_MILEAGE_BELOW_LAST`).
* Próximo vencimiento = último + intervalo efectivo (propio del vehículo o del plan). Cambiar los intervalos del plan o
  del vehículo recalcula.
* Km actual **estimado**: última lectura (historial, sin las marcadas `decreased`) + promedio km/día entre la primera y
  la última × días desde la última. Con una sola lectura (o el mismo día) se usa la lectura; sin lecturas, `vehicles.mileage`.
* Estado: vence por km o por fecha, lo que ocurra primero. `overdue` si faltan ≤ 0 km o la fecha pasó; `due_soon`
  dentro de los umbrales de `config/maintenance.php`; `unknown` si nunca se registró (no genera alerta).
* Alertas: solo planes activos, vehículos no eliminados y mantenimientos con registro; vencidos primero, con datos de
  contacto del cliente. Los avisos automáticos por email se agregaron en la FASE 23 (ver 2.36).
* La ruta `PATCH vehicles/{vehicle}/maintenances/{maintenancePlan}` usa `withoutScopedBindings()`: el plan no es una
  relación del vehículo.

Endpoints: CRUD `maintenance-plans` (PATCH para editar/activar), `GET|POST vehicles/{vehicle}/maintenances`,
`PATCH vehicles/{vehicle}/maintenances/{maintenancePlan}`, `GET maintenance/alerts?status=overdue|due_soon`.
Permisos: `maintenance-plans.index|show|store|update|destroy`, `vehicle-maintenances.index|record|configure`,
`maintenance-alerts.index`. Mecánico: ver planes y el estado del vehículo; recepcionista: además registrar y ver
alertas; jefe-taller: además configurar intervalos del vehículo; ABM de planes: administrador.

---

# 2.33 Reportes y dashboard (FASE 20)

Solo lectura y sin tablas nuevas. `ReportService` (reutiliza `PaymentService::summary` y `MaintenanceService::alerts`),
`ReportController`, `ReportRequest` (`date_from`/`date_to`, por defecto el mes en curso; `group_by` = day|month).
Solo JSON (la exportación queda para más adelante).

| Endpoint | Contenido | Fecha que filtra | Permiso |
|---|---|---|---|
| `GET dashboard` | Bloques según permisos: `workshop` (siempre), `sales`, `budgets`, `profitability`, `productivity`, `inventory`, `maintenance` | mes en curso | `dashboard.view` (+ el permiso de cada bloque) |
| `GET reports/sales` | Facturas emitidas: totales por tasa, IVA, descuento, ticket promedio, por día/mes, por condición; cobrado por medio; anuladas | emisión de la factura | `reports.sales` |
| `GET reports/budgets` | Por estado actual (cantidad y monto); tasa de aprobación = aprobados / (aprobados + rechazados) | creación del presupuesto | `reports.budgets` |
| `GET reports/profitability` | Cierres: venta, costos, comisiones, margen y %; 5 OT de menor y mayor margen; cuántas con costo incompleto | cierre de la OT | `reports.profitability` |
| `GET reports/productivity` | Por mecánico: OT como responsable, trabajos, venta de mano de obra atribuida (total × participación), comisiones, horas y costo | cierre de la OT | `reports.productivity` |
| `GET reports/inventory` | Valor del stock y bajo mínimo (a hoy; sin stock = 0); 10 más consumidos (neto de devoluciones) y movimientos por tipo | movimiento | `reports.inventory` |
| `GET reports/clients` | 10 clientes que más facturan, clientes nuevos, saldos por cobrar por cliente (a hoy), 10 vehículos con más visitas | emisión / alta / recepción | `reports.clients` |

Importes de ventas y clientes: con IVA (factura). Rentabilidad y productividad: sin IVA (cierre).
Acceso por área: cajero `sales` y `clients`; recepcionista `budgets`; jefe-taller `productivity`; depósito `inventory`;
`profitability` solo administrador; mecánico solo el dashboard (bloque `workshop`). El bloque `maintenance` del
dashboard usa `maintenance-alerts.index`.

---

# 2.34 Compras (FASE 21, 2026-10-20)

Documento de compra a un proveedor que reemplaza la entrada manual de tipo `purchase`. Decisiones del usuario:

| Tema | Decisión |
|---|---|
| Flujo | `draft → received → cancelled`. Sin orden de compra ni recepciones parciales: al recibir entra todo |
| IVA | Costos cargados **con IVA incluido**, tasa 10/5/exenta por línea (10 por defecto, `invoice.default_tax_rate`). Al inventario entra el costo sin IVA: costo × 100 / (100 + tasa), 4 decimales. IVA de la compra liquidado como en la venta (÷ 11, ÷ 21), en guaraníes |
| Pagos al proveedor | Fuera de alcance: solo condición `cash|credit` (reusa `InvoiceCondition`) y vencimiento |
| Entrada manual | `POST /inventory/entries` solo acepta `initial`; `purchase` → `422 VALIDATION_ERROR` (`errors.type`) |
| Anular | Solo recibidas, con motivo: una salida `purchase_cancellation` por línea (al costo promedio, como toda salida). Sin stock disponible suficiente → `INSUFFICIENT_STOCK`. El `last_cost` no se revierte. El borrador no se anula: se elimina (soft delete) |
| Documento | Para recibir: número y fecha de la factura del proveedor (fecha no futura); el número no se repite para el mismo proveedor entre compras recibidas (`PURCHASE_DUPLICATE_DOCUMENT`, 409). Timbrado opcional |
| Vínculo proveedor | Al recibir se crea el vínculo `part_supplier` si no existía, o se actualiza `last_cost` (costo sin IVA) |
| Roles | `deposito` todo; `cajero` y `jefe-taller` solo consulta |

Datos (migración `000046`): `purchases` (código `COM-000001`, proveedor, estado, documento del proveedor, condición,
vencimiento, totales por tasa, IVA 5/10, `total` con IVA y `net_total` sin IVA, recepción y anulación, soft delete) y
`purchase_items` (repuesto, cantidad, `unit_cost` y `total` con IVA, `tax_rate`, `net_unit_cost`,
`inventory_movement_id`). Los movimientos referencian la línea (`reference_type = purchase_item`).

Componentes: `PurchaseService` (orden de bloqueo compra → `part_stocks`), `InventoryService::registerPurchase()` y
`reversePurchase()`, `PurchaseController`, `Purchase`/`PurchaseItem` (inmutables una vez recibida: `PURCHASE_NOT_EDITABLE`),
`PurchaseException`. Errores: `PURCHASE_NOT_EDITABLE`, `PURCHASE_WITHOUT_ITEMS`, `PURCHASE_DOCUMENT_REQUIRED`,
`PURCHASE_DUE_DATE_INVALID` (a crédito sin vencimiento o anterior a la factura), `PURCHASE_NOT_RECEIVABLE`,
`PURCHASE_NOT_CANCELLABLE`, `PURCHASE_DUPLICATE_DOCUMENT`; se reutilizan `SUPPLIER_INACTIVE`,
`PART_DISCONTINUED_NO_ENTRY`, `INVENTORY_FRACTIONAL_QUANTITY_NOT_ALLOWED` e `INSUFFICIENT_STOCK`.

Respecto del diseño aprobado: la entrada manual de compra responde `VALIDATION_ERROR` (la Request ya valida el tipo)
en lugar de `INVENTORY_INVALID_MOVEMENT_TYPE`, y se agregaron `PURCHASE_DOCUMENT_REQUIRED` y `PURCHASE_DUE_DATE_INVALID`
para distinguir los motivos por los que no se puede recibir.

Tests: `tests/Feature/Purchases/PurchaseTest`. Los tests que cargaban stock con entradas manuales de compra usan el
helper `InventoryTestCase::purchase()` (compra exenta de una línea, recibida).

> **Desde la FASE 22 (2.35):** una compra con pagos vigentes no se anula (`PURCHASE_HAS_PAYMENTS`).

---

# 2.35 Pagos a proveedores (FASE 22, 2026-10-20)

Cuentas por pagar sobre las compras recibidas, con el mismo modelo que los pagos de clientes (FASE 16). Decisiones del
usuario:

| Tema | Decisión |
|---|---|
| Compras al contado | También quedan con saldo y se les registra el pago (el egreso queda con su medio de pago); no se dan por pagadas al recibir |
| Imputación | Un pago, una compra. Varios pagos por compra hasta el total. Sin pagos a varias compras, anticipos ni pagos sin compra |
| Anular la compra | Con pagos vigentes se rechaza (`PURCHASE_HAS_PAYMENTS`, 422), como `INVOICE_HAS_PAYMENTS`: primero se anulan los pagos |
| Medios | Se reutiliza `PaymentMethod` (`cash|card|transfer|check`); referencia obligatoria salvo efectivo, banco obligatorio en cheque (guardado también en transferencia); sin vuelto |
| Caja | `payments/summary` no cambia; los egresos tienen su propio resumen (`supplier-payments/summary`) |
| Roles | `cajero` todo; `deposito` y `jefe-taller` consultan pagos y cuentas por pagar (sin resumen de egresos) |
| Fuera de alcance | Retenciones de IVA y renta, notas de crédito del proveedor, moneda extranjera |

Datos (migración `000047`): `supplier_payments` (código `PAG-000001` = máximo id + 1, `purchase_id`, `supplier_id`
copiado de la compra para filtrar y agrupar, fecha, monto entero, medio, referencia, banco, `supplier_receipt_number`
opcional, notas, estado `registered|cancelled` con `PaymentStatus`, alta y anulación). `purchases` no cambia: pagado,
saldo y estado de pago (`unpaid|partial|paid`, `InvoicePaymentStatus`, solo en recibidas) se calculan
(`Purchase::paidTotal()`, `balance()`, `paymentStatus()`; el listado usa `withSum` como facturas).

Reglas: solo compras `received` (`PURCHASE_NOT_PAYABLE`); saldo cero → `PURCHASE_ALREADY_PAID`; monto mayor al saldo →
`SUPPLIER_PAYMENT_EXCEEDS_BALANCE`; fecha no futura (validación) ni anterior a la factura del proveedor
(`SUPPLIER_PAYMENT_DATE_BEFORE_DOCUMENT`). El pago no se edita ni se elimina (`SUPPLIER_PAYMENT_NOT_EDITABLE`, 409, a
nivel de modelo): se anula con motivo (`SUPPLIER_PAYMENT_NOT_CANCELLABLE` si ya lo está). Orden de bloqueo: compra → pago.

Cuentas por pagar (`GET accounts-payable`, permiso `accounts-payable.view`): a hoy, compras recibidas con saldo por
proveedor; vence en `due_date` (crédito) o en la fecha de la factura (contado) y está vencida si esa fecha es anterior a
hoy. Totales `balance`, `overdue`, `not_due`; con `supplier_id`, el detalle por compra con `days_overdue`.

Endpoints: `GET|POST purchases/{purchase}/payments`, `GET supplier-payments` (filtros proveedor, compra, medio, estado,
búsqueda, fechas), `GET supplier-payments/summary`, `GET supplier-payments/{supplierPayment}`,
`POST supplier-payments/{supplierPayment}/cancel`, `GET accounts-payable`. Permisos
`supplier-payments.index|show|store|cancel|summary` y `accounts-payable.view`. `PurchaseResource` suma `paid_total`,
`balance` y `payment_status`; `ShowPurchaseResource`, `payment_summary` y (en `show`) `payments`. Solo campos nuevos:
el contrato existente no cambia.

Componentes: `SupplierPaymentService`, `SupplierPaymentController`, `SupplierPayment`, `SupplierPaymentException`,
`StoreSupplierPaymentRequest`, `IndexSupplierPaymentRequest`, `AccountsPayableRequest` (se reutilizan
`CancelPaymentRequest` y `PaymentSummaryRequest`), `SupplierPaymentResource` y `ShowSupplierPaymentResource`.

Respecto del diseño aprobado: el campo del total pagado se llama `paid_total` (no `paid_amount`) por consistencia con
las facturas; el banco es obligatorio solo en cheque, igual que en los cobros.

Tests: `tests/Feature/Purchases/SupplierPaymentTest`. El helper `InventoryTestCase::purchase()` acepta además
`supplier_document_date`, `condition` y `due_date`.

---

# 2.36 Notificaciones (FASE 23, 2026-10-20)

Avisos a clientes por email y bandeja interna para el personal. Decisiones del usuario:

| Tema | Decisión |
|---|---|
| Destinatarios | Clientes (canal externo) y personal (bandeja en la app) |
| Canal | Email ahora; WhatsApp en otra fase. El canal es un enum (`NotificationChannel::MAIL`) y el contenido se arma aparte del canal (`ClientNotificationService::content()`) |
| Avisos al cliente | Presupuesto enviado, vehículo listo, factura a crédito por vencer / vencida, mantenimiento próximo / vencido |
| Avisos internos | Presupuesto aprobado/rechazado (recepcionista, jefe-taller), OT completada (cajero, recepcionista), repuesto sin stock en una OT (depósito, jefe-taller), cuentas por pagar vencidas (cajero) |
| Disparo | Automático, con reenvío manual; cada envío queda registrado |
| Facturas | Un aviso hasta 3 días antes del vencimiento; vencida, uno cada 7 días mientras tenga saldo |
| Mantenimiento | Un aviso al pasar a "por vencer" y otro al vencer, por ciclo (se reinicia al registrar el servicio) |
| Baja | General por cliente (`clients.notifications_enabled`, por defecto `true`) |

Datos (migración `000048`):
- `notifications`: tabla estándar de Laravel; `User` usa `Notifiable`.
- `client_notifications`: campos
  - `client_id`, `type` (`ClientNotificationType`), `channel`;
  - el documento de origen como `subject` polimórfico, con los alias `budget`, `reception`, `invoice` y `vehicle_maintenance` agregados al morphMap;
  - `recipient` (copia del email), `status` (`pending|sent|failed|skipped`, `ClientNotificationStatus`), `error`, `attempts`, `sent_at`;
  - `dedupe_key` único (nulo en los avisos que pueden repetirse);
  - `resent_from_id`, `created_by` (quien reenvió).
- `clients.notifications_enabled`.

Flujo:
- **Dónde se registran los avisos:** dentro de la transacción del evento.
  - `BudgetService::send`: un aviso en cada envío, también después de reabrir.
  - `ReceptionStatusService::sync`: cuando la recepción pasa a `completed`, con la clave `vehicle_ready:{id}` (un aviso por recepción).
  - `approve`/`reject`, `WorkOrderService::complete` y `WorkOrderPartService::tryReserve` (cuando la reserva falla por falta de stock): avisos internos.
- **Envío por cola:** `SendClientNotification` se despacha con `afterCommit`, así un rollback no deja correos enviados.
- **Omitidos:** un cliente sin email o con la baja activada queda `skipped` con el motivo. No es un error.
- **Fallos:** `ClientNotificationService::deliver()` registra el fallo como `failed` y no lanza excepción, así no rompe la operación que originó el aviso. No hay reintentos: el aviso se reenvía a mano.
- **Avisos internos:** se guardan sincrónicamente (canal `database`) con `WorkshopNotification`. `notifications.type` guarda `InternalNotificationType` por medio de `databaseType()`. Los destinatarios son los usuarios de los roles definidos en `config/notifications.php`; si un rol no existe, se ignora. El administrador no recibe avisos internos salvo que se agregue su rol a esa configuración.
- **Tarea diaria:** `notifications:send-reminders` (`NotificationReminderService`) está programada en `routes/console.php` a la hora `notifications.reminders_at`. Revisa:
  - facturas `issued` a crédito con saldo, con las claves `invoice_due_soon:{id}` e `invoice_overdue:{id}:{período}`;
  - las alertas de mantenimiento (`MaintenanceService::alerts`), con la clave `{tipo}:{vehicle_maintenance_id}:{last_done_at}`;
  - cuentas por pagar vencidas (`SupplierPaymentService::accountsPayable`): un aviso interno por usuario y día.

  Se puede correr varias veces por día sin repetir avisos. Para funcionar en el servidor hacen falta `php artisan schedule:run` cada minuto y `php artisan queue:work`.

Reenvío (`POST client-notifications/{id}/resend`): crea otro registro con el contenido actual del documento. Errores:
- `CLIENT_NOTIFICATION_PENDING` (409): el aviso todavía está en cola.
- `CLIENT_NOTIFICATION_SUBJECT_UNAVAILABLE` (422): el documento o el cliente fueron eliminados.
- `CLIENT_NOTIFICATIONS_DISABLED` (422) y `CLIENT_WITHOUT_EMAIL` (422).

Todas las excepciones son `NotificationException`.

Endpoints:
- `GET client-notifications`, con filtros `client_id`, `type`, `status`, `date_from`, `date_to` y `per_page`.
- `GET client-notifications/{clientNotification}`.
- `POST client-notifications/{clientNotification}/resend`.
- `GET clients/{client}/notifications`.
- Permisos `client-notifications.index|show|resend`, otorgados a recepcionista y cajero.
- Bandeja interna, sin permisos y solo con los avisos propios (los ajenos responden 404): `GET me/notifications` (filtros `unread` y `type`; `meta.unread_count`), `PATCH me/notifications/{id}/read` y `POST me/notifications/read-all`.
- Los recursos de cliente suman `notifications_enabled`, editable en el alta y la edición del cliente.

Componentes:
- Services: `ClientNotificationService`, `InternalNotificationService`, `NotificationReminderService`.
- Controllers: `ClientNotificationController`, `UserNotificationController`.
- Modelo `ClientNotification`, job `SendClientNotification`, mailable `ClientNotificationMail` (vista `mail.client-notification`), notificación `WorkshopNotification`, comando `SendNotificationReminders`.
- Requests `IndexClientNotificationRequest` e `IndexUserNotificationRequest`; resources `ClientNotificationResource`, `ShowClientNotificationResource` y `UserNotificationResource`.

Fuera de alcance: WhatsApp/SMS, preferencias de aviso por tipo, plantillas editables y reintentos automáticos.

Tests: `tests/Feature/Notifications/ClientNotificationTest`, `InternalNotificationTest` y `NotificationReminderTest`.

---

# 2.37 Exportación de reportes y comprobantes imprimibles (FASE 24, 2026-10-20)

Decisiones del usuario:

| Tema | Decisión |
|---|---|
| Reportes | Los seis reportes se exportan a CSV y a PDF |
| Comprobantes | PDF de presupuesto, factura, recibo de pago y comprobante de entrega |
| Factura | Es una representación, con la leyenda "Representación del comprobante – no válido como documento fiscal". No reemplaza al talonario ni a la factura electrónica |
| Datos del taller | En `.env` / `config/company.php` (`COMPANY_NAME`, `COMPANY_LEGAL_NAME`, `COMPANY_TAX_ID`, `COMPANY_ACTIVITY`, `COMPANY_ADDRESS`, `COMPANY_CITY`, `COMPANY_PHONE`, `COMPANY_EMAIL`), sin tabla ni endpoints |
| Email | El aviso de "presupuesto enviado" (FASE 23) adjunta el PDF del presupuesto |
| Permisos | Se reutilizan los existentes: el `show` de cada documento y el `reports.*` de cada reporte. No hay permisos nuevos |
| Estados | Se imprime todo con marca de agua: `BORRADOR` (presupuesto y factura en borrador), `RECHAZADO` y `CANCELADO` (presupuesto), `ANULADA` / `ANULADO` (factura, pago y entrega) |

Dependencia nueva: `barryvdh/laravel-dompdf` ^3.1 (dompdf 3.1). Usa la fuente DejaVu Sans, que trae los acentos, y la extensión `gd` para la firma.

Endpoints, todos de solo lectura. Responden el archivo con `Content-Disposition: attachment`; los errores siguen en JSON con el contrato habitual. Desde 2026-10-21 `config/cors.php` expone `Content-Disposition` para que el frontend lea el nombre del archivo (test `tests/Feature/Http/CorsTest`):
- `GET budgets/{budget}/pdf` (`budgets.show`).
- `GET invoices/{invoice}/pdf` (`invoices.show`).
- `GET payments/{payment}/receipt` (`payments.show`). Solo los pagos con recibo, es decir, los de facturas a crédito; los demás responden `PAYMENT_WITHOUT_RECEIPT` (422, `PaymentException`).
- `GET vehicle-deliveries/{delivery}/pdf` (`vehicle-deliveries.show`). Incluye la firma como imagen embebida.
- Reportes: el mismo endpoint con `?format=csv|pdf`, por ejemplo `GET reports/sales?format=csv`. Sin `format`, o con `format=json`, la respuesta es el JSON de siempre; un formato inválido responde `422 VALIDATION_ERROR`.

Exportación de reportes (`ReportExportService`): no recalcula nada, recorre el array de `ReportService`.
- Los valores sueltos van a la sección "Resumen" (Concepto / Valor). Los grupos anidados llevan un prefijo, por ejemplo "Cobrado — Total"; `totals` va sin prefijo.
- Cada lista de filas se convierte en una tabla.
- Las etiquetas en español salen de un mapa en el servicio. Una clave nueva sin etiqueta se muestra legible a partir de su nombre en inglés.
- El CSV está pensado para Excel en español: UTF-8 con BOM, separador `;` y coma decimal.
- El PDF sale en A4 apaisado.
- Nombre del archivo: `reporte-{nombre}_{desde}_{hasta}.{ext}`.

Componentes:
- Services: `DocumentPdfService` y `ReportExportService`.
- Trait `FileResponse` y helper de presentación `App\Support\Format` (`money`, `integer`, `quantity`; también lo usa `ClientNotificationService`).
- Vistas en `resources/views/pdf` (`layout`, `partials/parties`, `budget`, `invoice`, `receipt`, `delivery`, `report`).
- `ClientNotificationMail::attachments()`.

Fuera de alcance: Excel (.xlsx), monto en letras en el recibo, logo del taller, original/duplicado fiscal y KuDE/SIFEN.

Tests: `tests/Feature/Documents/DocumentPdfTest` y `tests/Feature/Reports/ReportExportTest`.

---

# 2.38 Auditoría avanzada (FASE 25, 2026-10-20)

Decisiones del usuario:

| Tema | Decisión |
|---|---|
| Qué se audita | Precios y costos, facturación y pagos, seguridad y usuarios, datos maestros |
| Mecanismo | Tabla propia (`audit_logs`) y trait `Auditable`, sin dependencias nuevas |
| Acceso | Solo el administrador (permisos `audit-logs.index` y `audit-logs.show`, sin asignar a otros roles) |
| Retención | Para siempre: los registros son inmutables y no se depuran |

Datos (migración `000049`): `audit_logs` con estos campos:
- `event` (`AuditEvent`);
- `auditable_type` (tipo corto: `part`, `invoice`, `part_supplier`…) y `auditable_id`;
- `user_id` (null en procesos sin usuario y en el login fallido);
- `old_values` y `new_values` (JSON);
- `ip_address`, `user_agent` y `created_at`.

`AuditLog` es inmutable: modificarlo o borrarlo responde `AUDIT_LOG_IMMUTABLE` (409, `AuditException`). El stock no se duplica, porque ya tiene su auditoría en `inventory_movements`.

Modelos con `Auditable`:

| Grupo | Modelos |
|---|---|
| Precios y costos | `Part`, `Service`, `BudgetItem`, `PartSupplier` (pivote del costo por proveedor; se identifica con `part_id` y `supplier_id` en los valores) |
| Facturación y pagos | `Invoice`, `InvoiceItem`, `Payment`, `SupplierPayment`, `Purchase`, `PurchaseItem` |
| Seguridad y usuarios | `User`, `Role`, `Permission` |
| Datos maestros | `Client`, `Vehicle`, `Mechanic`, `InvoiceStamp`, `MaintenancePlan` |

Reglas del registro (`AuditLogger`):
- **Qué se guarda:**
  - alta: todos los valores;
  - modificación: solo los campos que cambiaron, antes y después; si no cambió nada relevante, no se registra;
  - baja: los valores previos;
  - restauración (soft delete).
- **Qué se excluye o se enmascara:** se ignoran `created_at`, `updated_at` y `deleted_at`. `password`, `remember_token` y `token` se guardan como `********`.
- **Valores:** se guardan tal como quedan en la base. Por ejemplo, un precio cargado como 999 se registra como `999`.
- **Eventos sin modelo propio** (`AuditLogger::record()`):
  - `login` (con el usuario), `login_failed` (sin usuario, con el email intentado) y `logout`;
  - `permissions_synced` (rol, lista de permisos antes y después) y `roles_synced` (usuario, roles antes y después). Se registran aparte porque `syncPermissions` y `syncRoles` de Spatie no disparan eventos de modelo.
- **Límite conocido:** las actualizaciones masivas por query builder no disparan eventos y no se auditan (por ejemplo, `PartService::clearPreferredSupplier`, que desmarca el proveedor preferido).

Endpoints:
- `GET audit-logs`, con filtros `auditable_type`, `auditable_id`, `user_id`, `event`, `date_from`, `date_to` y `per_page` (20 por defecto).
- `GET audit-logs/{auditLog}`, que suma `user_agent` y el email del usuario.

Componentes: `AuditLogger`, trait `App\Traits\Auditable`, `AuditLog`, `AuditEvent`, `AuditException`,
`AuditLogController`, `IndexAuditLogRequest`, `AuditLogResource` y `ShowAuditLogResource`.

Fuera de alcance: auditar lecturas (quién vio qué), depuración automática, exportación de la auditoría y
alertas por eventos sospechosos.

Tests: `tests/Feature/Audit/AuditLogTest`.

---

# 2.39 Ajustes de datos maestros para el frontend (2026-10-21)

Surgen de construir las pantallas de datos maestros del frontend (`../synctaller-frontend`, fase F1):

- **Bajas del catálogo de vehículos:** `VehicleCatalogService::deleteBrand()` / `deleteVehicleModel()` (antes la
  lógica estaba en los controllers). Si hay vehículos vigentes (no eliminados) con esa marca o modelo →
  `BRAND_HAS_VEHICLES` / `VEHICLE_MODEL_HAS_VEHICLES` (409, `VehicleCatalogException`), igual criterio que
  `SERVICE_IN_USE`. Una marca con modelos vigentes tampoco se elimina (`BRAND_HAS_MODELS`, 409; decisión del
  usuario): se eliminan primero sus modelos.
- **RUC/CI en el listado de clientes:** `ClientResource` suma `document_number` (antes solo estaba en el detalle).
- **Costo y comisión del mecánico:** no admiten `null` en alta ni edición (`422`; ver 14, punto 10).
- **Filtro `brand_id`** en `GET vehicle-models` (para el select de modelos del formulario de vehículo).
- **Vaciar opcionales al editar:** las ediciones de cliente (`notes`), vehículo (`chassis`, `plate`, `year`, `color`,
  `engine_number`, `mileage`, `fuel_type`, `transmission`, `notes`) y mecánico (`specialty`, `hire_date`, `notes`)
  aceptan `null`, igual que el alta. Antes `sometimes` sin `nullable` respondía 422 y el campo no se podía vaciar.
  `hour_cost` y `commission_percentage` del mecánico quedan sin `nullable` (columna `NOT NULL`; ver 14, punto 10).

Tests: `tests/Feature/MasterData/BrandAndVehicleModelTest`, `VehicleTest`, `ClientTest`, `MechanicTest`.

---

# 2.40 Opcionales del catálogo de repuestos (2026-10-21)

Surgen de las pantallas de repuestos e inventario del frontend (fase F2), con el mismo criterio que 2.39:

- **Vaciar opcionales al editar:** repuesto (`sku`, `part_number`, `description`, `brand`), proveedor (`tax_id`,
  `contact_name`, `phone`, `email`, `address`, `notes`) y vínculo repuesto–proveedor (`supplier_part_code`,
  `last_cost`) aceptan `null`, igual que el alta. Antes `sometimes` sin `nullable` respondía 422.
- **`minimum_stock`:** el alta lo aceptaba como `null` y la columna es `NOT NULL DEFAULT 0` → 500. Ahora es opcional
  pero no vacío en alta y edición (`null` → 422), como el costo y la comisión del mecánico. `PartService::create()`
  refresca el modelo, así la respuesta del alta trae el 0 por defecto en lugar de `null`.
- **Nombre del proveedor:** en la edición `null` → 422 con mensaje propio (`required`).
- **`last_cost` al editar el vínculo:** mismos límites que al vincular (`min:0`, `max:99999999.99`); antes aceptaba
  negativos.

Tests: `tests/Feature/Parts/PartTest`, `SupplierTest`, `PartSupplierTest`.

---

# 2.41 Contrato de recepciones y diagnósticos (2026-10-22)

Surgen de las pantallas de recepción y diagnóstico del frontend (fase F3):

- **Valores crudos (decisión del usuario):** `ReceptionResource`, `ShowReceptionResource` y `DiagnosticResource`
  devuelven las fechas como `Y-m-d` y `mileage` entero (antes `"11/10/2026"` y `"Km: 125000"`); `diagnosed_at`,
  `created_at` y `updated_at` de diagnósticos y fotos, en ISO. El frontend formatea.
- **Checklist alcanzable:** el detalle de la recepción trae `check_list` (`id`, `status`); antes no había forma de
  conocer el id para `reception-check-lists/{id}`.
- **Vehículo en la recepción:** `chassis`, `brand`, `model` y `color` con `value`/`label` (antes `vin`, columna
  inexistente, siempre `null`). El listado suma `client_id`, `vehicle_id`, ids de cliente y vehículo y
  `estimated_delivery_date`; el detalle, `problem_description`.
- **Modelo del vehículo en diagnósticos:** `DiagnosticResource` usaba `vehicle->model` (relación inexistente) y el
  modelo salía siempre `null`; ahora `vehicleModel`. El mecánico suma `id`.
- **`ShowDiagnosticResource`:** suma `requires_parts` y `requires_repair`.
- **Opcionales y columnas `NOT NULL`:** la edición del diagnóstico vacía mecánico, queja, diagnóstico y recomendación
  con `null`. `priority` en `null` daba 500 en el alta (`NOT NULL DEFAULT medium`), igual que `fuel_level` de la
  recepción (`NOT NULL DEFAULT empty`, alta y edición) y `severity`/`status` del ítem en la edición: ahora `422`. El alta del diagnóstico refresca el modelo para devolver la prioridad por defecto.

Entorno local (no versionado): `public/storage` apuntaba a la carpeta anterior al renombrado
(`www/presupuestador/...`) y `APP_URL` era `http://localhost:8000`; se rehízo el enlace (`storage:link`, en Windows
borrar antes el junction con `rmdir`) y `APP_URL=http://synctaller-backend.test`.

Tests: `tests/Feature/Receptions/ReceptionTest`, `DiagnosticTest`.

---

# 2.42 Contrato de presupuestos (2026-10-22)

Surgen de las pantallas de presupuestos del frontend (fase F4):

- **Ítems con su catálogo:** el detalle (`GET budgets/{id}` y las acciones que lo devuelven) carga `items.part` e
  `items.service`; antes traía `part_id` / `service_id` sin código ni nombre.
- **Vehículo de la recepción:** listado y detalle cargan marca y modelo (`ReceptionResource` ya los expone, 2.41).
- **Editar ítems:** la descripción admite `%` como el alta (un ítem creado con `%` no se podía editar) y `notes`
  acepta `null`.

Tests: `tests/Feature/Budgets/BudgetLifecycleTest`.

---

# 2.43 Contrato de órdenes de trabajo (2026-10-22)

Surgen de las pantallas de OT del frontend (fase F5):

- **Mecánico de cada asignación:** `WorkOrderItemMechanicResource.mechanic` suma `user_id`. El mecánico solo puede
  cargar sus propias horas (si no, 403); sin el usuario el frontend no podía saber qué asignación es suya.
- **Vehículo de la recepción:** listado y detalle (también las acciones sobre ítems) cargan marca y modelo.

Tests: `tests/Feature/WorkOrders/WorkOrderLifecycleTest`.

---

# 2.44 Contrato de cobros (2026-10-22)

Surge de la pantalla de cobros del frontend (fase F6):

- **Cliente en el listado de pagos:** `PaymentResource` suma `customer_name` (de la factura, igual que
  `invoice_number`, solo con la factura cargada). La búsqueda ya filtraba por cliente pero el listado no lo mostraba.

Tests: `tests/Feature/Payments/PaymentTest`.

---

# 2.45 Contrato de entregas (2026-10-22)

Surge de la pantalla de entregas del frontend (fase F7):

- **Cliente en el listado de entregas:** `VehicleDeliveryResource` suma `client_name` (con el cliente cargado, como
  `plate` con el vehículo); el listado carga además el cliente. Antes solo traía `client_id`.

Tests: `tests/Feature/Deliveries/VehicleDeliveryTest`.

---

# 2.46 Recepción cerrada, diagnóstico cerrado y envío de presupuestos (2026-10-22)

Surgen de las pruebas del usuario en el frontend; las cuatro reglas son decisión suya:

- **Recepción cerrada (`delivered|cancelled`) no se modifica:** editar la recepción y sus fotos, editar o eliminar sus
  diagnósticos (con ítems y fotos) y editar, enviar, aprobar, rechazar, reabrir o eliminar sus presupuestos →
  `RECEPTION_CLOSED` (`ReceptionStatusService::ensureEditable()`). Cancelar un presupuesto sí se permite (deja de estar
  pendiente). Reemplaza lo anterior: una recepción entregada se podía corregir (`ReceptionTest` lo exigía; ahora exige
  el rechazo).
- **Cancelar la recepción cancela sus presupuestos `draft|sent`** (uno por uno, quedan en la auditoría). El aprobado
  sin OT queda aprobado.
- **Diagnóstico terminado o cancelado:** sus ítems y fotos no se agregan, editan ni eliminan (`DIAGNOSTIC_NOT_EDITABLE`,
  nueva `DiagnosticException` y `DiagnosticService`); volver el diagnóstico a `in_progress` permite corregirlos.
- **Enviar un presupuesto exige los diagnósticos de la recepción terminados o cancelados**
  (`BUDGET_DIAGNOSTICS_PENDING`, el mensaje lista los números). El borrador se arma igual. `BudgetItemSeeder` da por
  terminados los diagnósticos de ejemplo antes de enviar.
- **Orden del listado de diagnósticos:** por `diagnosed_at` descendente (antes `latest()`, por fecha de carga: los
  datos de ejemplo salían desordenados).

Tests: `ReceptionStatusTest`, `ReceptionTest`, `DiagnosticTest`, `BudgetLifecycleTest`.

---

# 2.47 Mecánicos eliminados en OT y diagnósticos (2026-10-22)

Surge de la revisión de F9 sobre datos reales: la OT-000001 mostraba el responsable vacío en su detalle y en el
historial porque su mecánico estaba eliminado (soft delete). `WorkOrder::mechanic()` y `Diagnostic::mechanic()` eran las
únicas relaciones con `Mechanic` sin `withTrashed()` (asignaciones, comisiones y el servicio del plan ya lo tenían).
Ahora lo incluyen. Test: `tests/Feature/WorkOrders/DeletedMechanicTest` (falla sin el cambio).

---

# 2.48 Datos maestros en uso (2026-10-22)

Surgen de las pruebas del usuario; las reglas son decisión suya. Las bajas son lógicas y las claves foráneas no se
disparaban: se podía eliminar lo ya usado y los documentos viejos lo mostraban deformado (`ABC123//deleted_…`) o sin
nombre.

- **Mecánico** con OT, asignaciones o diagnósticos: `MECHANIC_IN_USE` (409); se pasa a inactivo (estado existente).
- **Vehículo** con recepciones: `VEHICLE_IN_USE` (409); si cambió de dueño se edita su cliente.
- **Ítem de checklist** usado en recepciones: `CHECKLIST_ITEM_IN_USE` (409). Nueva columna `is_active` (migración
  000050, `true` por defecto): editable, filtrable en `GET checklists?is_active=` y los inactivos no se agregan a los
  checklists nuevos. El índice agrupa la búsqueda para que el `OR` no se saltee ese filtro.
- **Servicio**: además de presupuestos, no se elimina si lo usa un plan de mantenimiento (`SERVICE_IN_USE`); usado en
  presupuestos no cambia de nombre (`SERVICE_NAME_NOT_EDITABLE`, 422; el nombre se ve en los presupuestos viejos). En
  los datos locales, los servicios 2 y 3 ya estaban eliminados con planes que los usan.

Tests: `tests/Feature/MasterData/InUseDeletionTest`.

---

# 2.49 Repuestos extra con aprobación del cliente (2026-10-23)

Resuelve la deuda #11 (sección 14) con la opción 3: el extra se registra con la respuesta del cliente antes de
reservarse, consumirse o facturarse. Las decisiones de negocio son del usuario.

| Tema | Decisión |
|---|---|
| Flujo | `POST work-orders/{id}/parts` crea el extra en `awaiting_approval` (estado nuevo, "Pendiente de aprobación") sin reservar. `POST …/parts/{part}/approve` lo pasa a `pending` e intenta reservar, como una línea presupuestada (sin stock queda `pending` y avisa a depósito). `POST …/parts/{part}/reject` lo deja `cancelled` |
| Quién decide | Permiso nuevo `work-order-parts.approve`: recepcionista (que además recibe `work-order-parts.index`) y jefe-taller; el administrador por `Gate::before`. Depósito agrega extras pero no los aprueba; el mecánico no agrega ni aprueba |
| Registro | `client_decision` (`approved\|rejected`), `decided_at`, `decided_by` (usuario que lo cargó), `decision_channel` (`phone\|whatsapp\|in_person\|email\|other`, obligatorio), `decision_contact` (quién respondió, opcional) y `decision_notes` (obligatorio al rechazar). `WorkOrderPart` pasa a usar `Auditable` (tiene precio) |
| Lo aprobado | Cantidad y precio no cambian después de aprobar (`WORK_ORDER_PART_APPROVED_NOT_EDITABLE`; reenviar los mismos valores es válido); para otro número se cancela y se carga otro extra. No se consume más de lo aprobado (`WORK_ORDER_PART_EXCEEDS_APPROVED`); menos sí, y se factura lo usado |
| Líneas presupuestadas | Sin cambios: pueden usar más de lo reservado (decisión del usuario: queda como está) |
| Cierre y factura | `awaiting_approval` está en `openStatuses()`: con extras sin respuesta la OT no se completa (`WORK_ORDER_HAS_UNRESOLVED_PARTS`), así que no se factura. Cierre, factura y `variance` no cambian |
| Aviso | Interno `extra_part_awaiting_approval` a recepcionista y jefe-taller al agregar el extra. Sin email al cliente por ahora |
| Datos existentes | La migración 000051 pasa los extras abiertos (`pending\|reserved`) a `awaiting_approval` y libera su reserva con SQL. Los extras ya consumidos, devueltos o cancelados quedan sin decisión registrada. El `down()` los devuelve a `pending` sin reserva |

Errores nuevos (todos 422, `WorkOrderPartException`): `WORK_ORDER_PART_NOT_APPROVED` (reservar o consumir un extra
sin respuesta), `WORK_ORDER_PART_APPROVED_NOT_EDITABLE`, `WORK_ORDER_PART_EXCEEDS_APPROVED`. Aprobar o rechazar algo
que no está `awaiting_approval` (incluidas las líneas presupuestadas) da `WORK_ORDER_PART_INVALID_STATUS_TRANSITION`.

Contrato: `WorkOrderPartResource` agrega `client_decision` (`value`, `label`, `decided_at`, `decided_by`,
`channel` `{value, label}`, `contact`, `notes`), `null` si no hay decisión. Cambio visible: el alta de un extra devuelve
`awaiting_approval` en vez de `reserved|pending`. El frontend debe mostrar las acciones de aprobar y rechazar.

Tests: `tests/Feature/WorkOrderParts/ExtraPartApprovalTest` (flujo, errores, permisos por rol, aviso y la migración
sobre datos previos). Después de actualizar: `php artisan migrate` y `php artisan db:seed --class=RolesAndPermissionsSeeder`.

---

# 2.50 Preferencias visuales del usuario y logo del sistema (2026-10-24)

Pedido del frontend: cada usuario personaliza la apariencia (los ajustes de tema de Tabler 1.7) y el administrador
carga un logo que aparece en el login, la barra de menú, el ícono de la pestaña, los PDF y los emails a clientes.

| Tema | Decisión |
|---|---|
| Preferencias: datos | Tabla `user_preferences` (migración 000052): una fila por usuario, `settings` JSON con solo los valores distintos del predeterminado. No es `Auditable`: es personalización de la interfaz y no tocar `users` evita que cada cambio de color quede en la auditoría |
| Preferencias: catálogo | `App\Support\UserPreferences` (`OPTIONS` y `DEFAULTS`): `theme`, `theme_primary`, `theme_base`, `theme_font`, `theme_radius`, `layout`, `navbar_position`, `navbar_style`, `navbar_theme`, `navbar`, `sidebar`. Los predeterminados reproducen el aspecto original (barra lateral oscura y expandida, azul, tema automático). Un valor guardado que salga del catálogo vuelve al predeterminado al leerlo |
| Preferencias: API | `GET\|PATCH\|DELETE me/preferences` sin permiso (cada usuario, las suyas). `PATCH` es parcial: al menos una clave, solo claves del catálogo (una desconocida da 422 en esa clave). `DELETE` restaura los predeterminados. `GET /me` agrega `data.preferences` completo. El `PATCH` bloquea la fila (`lockForUpdate`): dos cambios simultáneos de claves distintas (dos clics rápidos) se combinan en lugar de pisarse |
| Logo: datos | Tabla `system_settings` (clave/valor, migración 000053), modelo `SystemSetting` con `Auditable` (el cambio de logo queda en la auditoría). Clave `logo_path`; el archivo va al disco `public`, carpeta `branding`, con nombre nuevo en cada subida (la URL cambia y no queda en caché). El anterior se borra |
| Logo: API | `GET branding` público (el login lo necesita antes de autenticar): `logo_url` o `null`. `POST system-settings/logo` (multipart `logo`: PNG o JPG hasta 2 MB, los formatos que dompdf dibuja bien) y `DELETE system-settings/logo` (idempotente), con el permiso nuevo `system-settings.update`, solo del administrador |
| Logo en PDF | `DocumentPdfService::render()` pasa `logo` (data URI, `BrandingService::logoDataUri()`) a todas las vistas; `pdf/layout.blade.php` lo dibuja arriba de los datos del taller (48 px de alto). Cubre presupuesto, factura, recibo, entrega y reportes |
| Logo en emails | Cabecera HTML propia en `resources/views/vendor/mail/html/header.blade.php`: con logo muestra `cid:logo@synctaller`; `ClientNotificationMail` adjunta la imagen inline (`BrandingService::embedLogo()` en `Envelope(using: …)`). Un mailable markdown nuevo debe adjuntarla igual o la cabecera mostrará la imagen rota. La versión en texto plano no cambia |

Tests: `tests/Feature/Preferences/UserPreferenceTest` y `tests/Feature/Settings/SystemLogoTest` (14). Después de
actualizar: `php artisan migrate` y `php artisan db:seed --class=RolesAndPermissionsSeeder`.

**Logo negro y sin nombre (2026-10-24, decisiones del usuario).** El logo del taller es blanco sobre transparente y
ya lleva el nombre: el frontend muestra solo el logo (sin el texto "SyncTaller"; sin logo, el nombre) y hace falta
una versión que se lea sobre fondo claro.

| Tema | Decisión |
|---|---|
| Versión negra | Se genera sola al subir un PNG con transparencia (`App\Support\LogoImage`, GD): cada píxel visible pasa a negro conservando su alfa. Clave nueva `logo_black_path` en `system_settings`. Un JPG o un PNG opaco se guarda tal cual y no tiene versión negra (la anterior se borra) |
| Recorte | El mismo PNG se recorta a su contenido (márgenes transparentes fuera) y se limita a 1200 px de lado, antes de generar la negra. El logo real pasó de 677×369 a 476×137 (~0,2 s) |
| Contrato | `GET branding` (y las respuestas de subir y quitar) agrega `logo_black_url`: la versión negra o, sin ella, la misma URL que `logo_url`; `null` sin logo |
| Uso | PDF y emails usan la negra (fondo blanco). El frontend elige según el fondo: negra sobre fondo claro (login en modo claro, navegación "como la página" en claro), la subida sobre fondo oscuro |
| Nombre en los PDF | Con logo, `pdf/layout.blade.php` no muestra el nombre del taller (`legal_name`/`name`): el logo lo reemplaza. Actividad, dirección, teléfono, email y RUC siguen. Sin logo, el nombre como antes |

Un logo subido antes de este cambio no tiene versión negra hasta que se vuelve a subir.

**Favicon propio (2026-10-24, decisión del usuario).** El ícono de la pestaña dejó de ser el logo: un logo horizontal
no se lee a 16–32 px y lo habitual es un archivo aparte (isotipo o inicial). Clave `favicon_path` en
`system_settings`, `favicon_url` en `GET branding` y `POST|DELETE system-settings/favicon` (mismo permiso
`system-settings.update`). Formatos habituales: PNG o ICO cuadrado de 16 a 1024 px, o SVG, hasta 512 KB; se guarda
tal cual (no se pasa a negro: tiene que leerse en pestañas claras y oscuras). Un SVG con código ejecutable (scripts,
`foreignObject`, `iframe`, `embed`, `object`, eventos `on…`, `javascript:`) se rechaza con 422, porque queda público en el
disco y se ejecutaría al abrir su URL. Sin favicon, el frontend usa su ícono original. Tests:
`tests/Feature/Settings/SystemFaviconTest` (5).

---

# 2.51 Actualización a Laravel 13 y dependencias (2026-10-24)

Rama `chore/laravel-13`, en tres pasos con la suite completa en verde después de cada uno (369 tests):

| Paquete | Antes | Después |
|---|---|---|
| PHP (requisito) | `^8.2` | `^8.3` (Laravel 13 y spatie 8 lo exigen; el entorno ya era 8.3) |
| `laravel/framework` | 12.70 | 13.38 |
| `laravel/tinker` | 2.11 | 3.0 |
| `spatie/laravel-permission` | 6.25 | 8.3 |
| `phpunit/phpunit` | 11.5 | 12.5 |
| Sanctum, Pail, Pint, Sail, Mockery, Collision | — | últimas menores (Sanctum 4.3.3, Collision 8.9.5, …) |

* **spatie 6 → 8:** sin cambios en el código. Se compararon la configuración y la migración publicadas con las del
  paquete: el esquema es el mismo (solo cambia el estilo del stub) y la configuración agrega `models.team` y
  `models.default_model`, ambas `null` por defecto (equivale a no tenerlas). Todos los métodos, middlewares
  (`role`, `permission`, `role_or_permission`) y `UnauthorizedException` que usa la app siguen existiendo.
* **PHPUnit 12:** los tests no usan anotaciones en comentarios (`@test`, `@dataProvider`) ni mocks: sin cambios.
* **Configuración:** Laravel combina `config/*.php` con los valores del framework, así que las opciones nuevas de
  Laravel 13 (p. ej. `cache.stores.session`, `mail.markdown.extensions`, `config/images.php`) toman su valor por defecto.
  No se publicaron.
* **Verificación además de la suite:** prueba de humo contra Nginx (login, `/me`, listados, PDF y CSV de reportes, PDF
  de factura, errores 404/401 en JSON, preflight CORS desde `localhost:5173`) y las 19 pantallas principales del
  frontend sin errores HTTP ni de consola.
* No hay migraciones nuevas. En otro equipo: `composer install` con PHP 8.3 o superior.

---

# 2.52 Datos de prueba por escenarios (2026-10-24)

Pedido del usuario: más usuarios por rol y seeders que dejen cada módulo en cada uno de sus estados, creados por los
Services, para probar la app. Se mantuvo la estructura existente (un seeder por módulo en `DatabaseSeeder`, sin una
clase de demo aparte).

| Tema | Decisión |
|---|---|
| Usuarios | 17 (2 administradores, 3 recepcionistas, 2 jefes de taller, 2 cajeros, 2 de depósito, 6 mecánicos con fichas activas, inactiva y de licencia). `TEST_PASSWORD` (nueva en `.env.example`) para todos salvo `admin@mail.com.py` (`ADMIN_PASSWORD`) |
| Catálogos | 30 clientes (uno sin email, uno dado de baja de los avisos), 45 vehículos (uno sin placa), servicios, proveedores y repuestos en cada estado. Se corrigieron datos que no pasaban la validación de la API: teléfonos con 0 inicial (ahora `595…`) y dos colores fuera de `VehicleColor` |
| Escenarios | `ExampleScenarios`: 35 recepciones con su estado final (17 terminadas, 16 abiertas, 2 canceladas) y el plan de facturación de las terminadas (`BILLING`). Cada seeder de módulo los recorre por los Services, con el usuario del rol y el reloj en la fecha del evento (`Carbon::setTestNow`), repartidos en los últimos tres meses. La recepción de un escenario se identifica por número de visita del vehículo (no por fecha): volver a sembrar otro día no duplica |
| Seeders nuevos | `WorkOrderSeeder` (ciclo completo de la OT: ítems, reparto, horas, repuestos, extras; en uno solo porque los pasos se entrelazan), `ReceptionCancellationSeeder`, `InvoiceSeeder`, `PaymentSeeder`, `VehicleDeliverySeeder`, `PurchaseSeeder`, `SupplierPaymentSeeder`, `InventoryAdjustmentSeeder`, `VehicleMaintenanceSeeder`, `CommissionSeeder`, `NotificationSeeder` |
| Kardex | `inventory_movements` guarda el saldo acumulado y se lista por id: los movimientos se siembran en orden cronológico (carga inicial hace 100 días, compras que mueven stock antes de los escenarios, ajustes al final). El test lo verifica |
| Avisos | Los registran los Services durante los escenarios; `NotificationSeeder` envía con el mailer `array` (nunca sale un email), hace fallar uno con un SMTP inexistente y lo reenvía, corre los recordatorios diarios y marca leídos los avisos internos de más de tres días. Los avisos de presupuesto antiguos se marcan enviados sin generar el PDF (~0,35 s cada uno); los tres más recientes pasan por el Service |
| Rendimiento | La suite tardaba 218 s; ahora ~178 s. `DatabaseSeederTest` pasó de cuatro siembras con la cola `sync` (cada aviso de presupuesto armaba el PDF: ~10 s por siembra) a una siembra más la de idempotencia con `Queue::fake()` (~10 s en total). `VehicleModelSeeder` inserta en una sola consulta (2,1 s → 13 ms). `phpunit.xml` desactiva la sincronización a disco de SQLite. Sembrar una base vacía tarda ~15 s; lo que queda es el costo real de los Services (`WorkOrderSeeder`, ~4.000 consultas) |
| Sin cambiar | `DatabaseSeeder` sigue con `WithoutModelEvents` (la siembra no deja registros de auditoría). `RolesAndPermissionsSeeder` (1,5 s) no se optimizó: lo usan todos los tests y audita los permisos |

Los emails de los clientes de ejemplo usan el dominio reservado `example.com`: no pasan la validación con
`EMAIL_DNS_CHECK=true` (al editarlos desde la app hay que cambiar el email o desactivar la verificación en desarrollo).

---

# 2.53 Catálogo de ítems de diagnóstico (2026-10-24)

Pedido del usuario: armar el diagnóstico eligiendo ítems reutilizables, como se eligen repuestos y servicios en el
presupuesto. Plan y decisiones en `../synctaller-frontend/docs/plan-desarrollo.md`; reglas vigentes en `CLAUDE.md`,
6.3.

| Tema | Decisión |
|---|---|
| Modelo | `diagnostic_item_templates` (migración `000054`, `DIA-000001`, soft delete, `Auditable`) y `diagnostic_items.diagnostic_item_template_id` (obligatorio desde la `000055`, ver el ajuste más abajo) |
| Campos | Título (único entre los vigentes), categoría de servicio opcional, descripción, severidad sugerida, requiere reparación / reemplazo, tiempo y recomendación. Sin costo estimado: se desactualiza y el precio real va en el presupuesto. Sin estado del ítem: es el resultado de la revisión (arranca `pending`) |
| Copia, no referencia | El ítem del diagnóstico copia los valores al crearse (los enviados pisan los del catálogo). Editar, desactivar o eliminar la entrada no cambia los diagnósticos hechos; el ítem conserva el vínculo (`template`, con `deleted` si se eliminó). Por eso la baja se permite aunque esté en uso (a diferencia de servicios y checklist) |
| Permisos | `diagnostic-item-templates.index\|show` para el mecánico; además `store\|update\|destroy\|change-status` para administrador y jefe de taller |
| Datos de prueba | `DiagnosticItemTemplateSeeder`: 32 entradas por categoría, una inactiva (Carburador). Cada ítem de los escenarios sale de una entrada del catálogo |
| Fuera de alcance | Generar ítems del presupuesto a partir del diagnóstico (el usuario lo descartó por ahora) y elegir varios ítems a la vez |

**Ajuste del mismo día (rama `fix/items-diagnostico-desde-catalogo`).** La primera versión dejaba el catálogo opcional
(ítems libres, título editable y `save_as_template` para guardar un ítem libre en el catálogo). El usuario aclaró que
quería elegir el ítem del catálogo como se elige el repuesto en la línea de compra:

| Tema | Decisión |
|---|---|
| Catálogo obligatorio | `diagnostic_item_template_id` es `required` en el alta y `prohibited` al editar; `title` es `prohibited` en ambos (sale del catálogo). Se quitó `save_as_template` |
| Base de datos | Migración `000055`: cada ítem libre existente se vincula a la entrada vigente con su mismo título o a una nueva creada con sus datos; después la columna pasa a `NOT NULL` con `restrictOnDelete` (la baja del catálogo sigue siendo lógica). En la base local de prueba, los 2 ítems "Abolladura en puerta trasera" pasaron a `DIA-000033` |
| Seeder | El ítem de ejemplo que no estaba en el catálogo ("Abolladura en puerta trasera") usa "Abolladura en carrocería"; `DiagnosticItemSeeder` exige la entrada (`firstOrFail`) |

---

# 2.54 Recordatorios de factura al destinatario correcto (2026-10-28)

Entrega E2 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §9). Los recordatorios de
vencimiento iban siempre a `invoice.client`, el dueño de la recepción, aunque en el borrador se hubiera cambiado el RUC o
CI para facturar a otro (por ejemplo, a una aseguradora): el dueño recibía el aviso de una deuda que no era suya.

| Tema | Decisión |
|---|---|
| Regla | Si el `customer_tax_id` de la factura no coincide con el `document_number` del cliente, el recordatorio (`invoice_due_soon` / `invoice_overdue`) se registra `skipped` con el motivo "La factura está a nombre de otro destinatario." Es el mecanismo existente: no se envía email ni se crea otro canal, y la clave de deduplicación evita repetirlo |
| Comparación | Mayúsculas y sin espacios, sin el dígito verificador final (`-N`): CI `1234567` y RUC `1234567-8` cuentan como la misma persona (decisión del usuario del 2026-10-28). Sin documento del cliente no hay con qué comparar y el aviso sale como antes |
| Reenvío | `POST client-notifications/{id}/resend` sigue permitido sobre el aviso omitido y va al cliente (decisión del usuario del 2026-10-28): es una acción manual registrada. Sin `error_code` nuevo |
| Código | `NotificationReminderService::invoiceReminders` (`isAddressedToClient`) y un parámetro opcional `$skipReason` en `ClientNotificationService::notify` |
| Sin cambiar | Contrato de la API, permisos, migraciones, los demás avisos y los recordatorios de facturas a nombre del dueño |

---

# 2.55 Códigos internos con bloqueo (2026-10-28)

Entrega E3 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §9; política P4, §6.4).
Seis altas calculaban el código como `withTrashed()->max('id') + 1` sin bloqueo (`BUD-`, `OT-`, `COM-`, `REP-`, `SRV-`,
`DIA-`): dos altas simultáneas de la misma serie obtenían el mismo código y la segunda fallaba con 500 por el índice
único de `code`.

| Tema | Decisión |
|---|---|
| Contador | Tabla `document_sequences` (migración `000056`: `key` único, `next_value`). `DocumentSequenceService::create(DocumentSeries, closure)` bloquea la fila de la serie con `lockForUpdate` dentro de la transacción del alta, arma el código (prefijo + 6 dígitos, formato sin cambios) y lo pasa al closure que crea el documento. Si el alta falla, el rollback revierte el incremento. Solo se serializan las altas de la misma serie |
| Valor inicial | En el **primer uso** (decisión del usuario del 2026-10-28, en lugar de cargarlo en la migración): el servicio inserta la fila con `insertOrIgnore` y el máximo id + 1 de la tabla, incluidos los eliminados, que es el mismo código que daba el cálculo anterior. La migración es solo estructura. Las series futuras (`ASE-`, `SIN-`) solo se agregan al enum `DocumentSeries` |
| Códigos usados | Con la fila bloqueada, si el código ya existe (también eliminado) se salta al siguiente. Cubre códigos cargados sin pasar por la serie (por ejemplo, la migración `000055` con `DIA-`) |
| Duplicado | Si igual se viola la unicidad del código, la API responde `409 DOCUMENT_CODE_CONFLICT` (`AppException`), nunca 500 (decisión del usuario del 2026-10-28: solo para los códigos, sin mapeo global). Otras violaciones de unicidad no cambian |
| Orden de bloqueo | Documento → serie: la serie siempre al final, y cada serie la usa un solo servicio (sin ciclos). La OT retiene la fila de su serie mientras reserva repuestos en la misma transacción |
| Huecos | Se aceptan (P4): no son documentos fiscales. Facturas, `REC-`, `ENT-` y `PAG-` no cambian (ya usaban `lockForUpdate`) |
| Pruebas | `tests/Feature/Documents/DocumentSequenceTest` (formato, continuidad, salto, rollback, series independientes, 409). La concurrencia real solo se reproduce en MySQL: `tests/Manual/document_sequence_concurrency.php` (N procesos piden códigos a la vez; verifica que no haya repetidos ni errores y restaura el contador) |
| Sin cambiar | Rutas, Resources, permisos y formato de los códigos |

---

# 2.56 Importes del presupuesto en decimal(14,2) (2026-10-28)

Entrega E4 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §9). `budgets.subtotal|tax|total`
y `budget_items.unit_price|total` eran `decimal(10,2)`: tope de 99.999.999,99 Gs. Como los Requests no limitaban precio
ni cantidad y MySQL corre en modo estricto, un presupuesto mayor (por ejemplo, una chapería de 120 millones) fallaba
con 500.

| Tema | Decisión |
|---|---|
| Columnas | Migración `000057`: las cinco columnas pasan a `decimal(14,2)` con `->change()`, conservando default y nulabilidad. Es la misma precisión que las facturas. `quantity` queda en `(10,2)`. El `down()` se detiene con un mensaje si algún importe no entra en `(10,2)`, en lugar de truncar |
| Topes | Decisión del usuario del 2026-10-28: `unit_price` ≤ 999.999.999.999,99 y `quantity` ≤ 99.999.999,99 en los Requests (422 `VALIDATION_ERROR`). Si el total del ítem o el del presupuesto con IVA no entra en `(14,2)`: `BUDGET_AMOUNT_OUT_OF_RANGE` (422, `BudgetService::ensureAmountInRange`). Ningún importe llega a la base fuera de rango |
| Transacción | `BudgetItemController::store\|update` creaba o editaba el ítem y recalculaba sin transacción: si el recálculo fallaba, el ítem quedaba guardado. Ahora las dos operaciones van en `DB::transaction`. La lógica de ítems sigue en el controller (no se movió al Service: fuera de alcance) |
| Alcance | Solo presupuestos (decisión del usuario del 2026-10-28). **Observación sin cambios:** `parts.cost_price\|sale_price`, `part_supplier.last_cost` y `mechanics.hour_cost` siguen en `(10,2)`, con un máximo de 99,9 millones por unidad; `work_order_closings` está en `(12,2)` y alcanza |
| Pruebas | `tests/Feature/Budgets/BudgetAmountRangeTest`: presupuesto de 150.000.000, topes, total del ítem y del presupuesto fuera de rango (sin guardar nada) y edición fuera de rango. SQLite no aplica la precisión de los decimales: la ampliación se verificó en la base MySQL de desarrollo el 2026-10-28. Las cinco columnas quedaron en `decimal(14,2)` con sus defaults, y un presupuesto de 150.000.000 se guardó con total 165.000.000,00 (dentro de una transacción revertida). El `down()` se detuvo con importes grandes, y sin ellos volvió a `(10,2)` y se reaplicó |

---

# 2.57 Registro de la respuesta del cliente al presupuesto (2026-10-28)

Entrega E5 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.4 y §9). `approve` y
`reject` solo cambiaban el estado: no quedaba quién lo registró, por qué medio respondió el cliente ni por qué importe.
`reopen` borraba `approved_at`, así que se perdía el historial, y `Budget` no estaba auditado. Es la base del control
de aceptación del dueño en siniestros (decisión B, M7).

| Tema | Decisión |
|---|---|
| Tabla | `budget_client_acceptances` (migración `000058`): `budget_id`, `kind` (`ClientAcceptanceKind`: `approval\|rejection`), `decided_by` (usuario que lo registra), `decided_at`, `channel` (`ClientDecisionChannel`, nulo), `contact` (nulo), `notes` (nulo) y `owner_amount` (`decimal(14,2)`). Claves foráneas `restrictOnDelete`. Las columnas de aseguradora (`insurer_authorization_id`, `deductible_amount`) y el tipo `re_acceptance` llegan con M7 |
| Importe | `owner_amount` = subtotal sin IVA al responder (decisión del usuario del 2026-10-28: desde E5). En M7, con aseguradora, pasa a ser la parte del dueño |
| Body | Opcional (decisión del usuario del 2026-10-28): `channel`, `contact` (≤ 150) y `notes` (3 a 500), con las reglas de la respuesta a un extra (`DecideBudgetRequest`). Sin body el contrato es el de siempre y la fila se crea igual; el motivo del rechazo también es opcional |
| Inmutable | `BudgetClientAcceptance` no se edita ni se elimina (`BUDGET_CLIENT_ACCEPTANCE_IMMUTABLE`, 409). Reabrir no toca las filas: una respuesta nueva es otra fila |
| Histórico | Sin filas para los presupuestos anteriores (decisión del usuario del 2026-10-28): no hay de dónde sacar quién lo registró ni por qué medio. Conservan `approved_at` |
| Contrato | El detalle del presupuesto (`show` y las respuestas de las transiciones) agrega `client_acceptances`, en orden, con `kind`, `decided_by` (`id`, `name`), `channel` y `owner_amount` (aditivo) |
| Auditoría | `Budget` y `BudgetClientAcceptance` usan `Auditable`. Como cada ítem recalcula los totales del presupuesto, esos cambios también quedan registrados (son precios) |
| Seeders | `BudgetItemSeeder` aprueba (por WhatsApp) y rechaza (por teléfono, con motivo) con el recepcionista del escenario. `DatabaseSeederTest` verifica una aprobación por presupuesto aprobado y los rechazos, incluido el del reabierto |
| Pruebas | `tests/Feature/Budgets/BudgetClientAcceptanceTest`: sin body, con datos, canal y notas inválidos, historial al reabrir (importe de cada respuesta), inmutabilidad y auditoría. Los tests de presupuestos existentes no cambiaron |

---

# 2.58 Spike T0 de tenancy (2026-10-28/29, descartado)

Prueba técnica de `spatie/laravel-multitenancy` **4.2.1** (P2) en una rama descartable. No se mergeó código ni se
aprobó la dependencia. El informe completo, con los criterios S1–S13, las mediciones y lo que se armó para retomarlo
en T2, está en `docs/spike-t0-informe.md`.

| Tema | Resultado |
|---|---|
| Críticos (S1, S2, S4, S5, S7) | Cumplen. Los 393 tests en verde con el paquete y un taller activo; aislamiento de base, tokens, `exists`, bindings, SQL crudo, permisos y cache; un worker alternando talleres sin cruces |
| No cumplen | S8 (un job con la base del taller caída consume su único intento) y S10 (la falla de un taller corta las migraciones de los demás). Los dos se resuelven con código propio ya previsto (§3.5, §3.8) |
| Rendimiento (S13) | Con OPcache, unos 30–45 ms más por request, por encima de los umbrales de G. Hay mitigaciones para probar en T2 |
| Hallazgos de diseño | El paquete identifica el taller al arrancar, no en un middleware (se usó un middleware propio con errores JSON). La cache y `failed_jobs` tienen que ir en la base central. Spatie Permission necesita una tarea propia para olvidar los permisos al cambiar de taller |
| Entorno local | OPcache quedó activado para `php-cgi` (copia del `php.ini` original junto al archivo). Las bases del spike se eliminaron |

---

# 2.59 Cliente persona o empresa (2026-10-29)

Entrega M1 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.1 y §9; decisión P3). El
cliente era siempre una persona: nombre y apellido obligatorios y solo letras, así que una aseguradora o una flota no
se podían cargar. El nombre se armaba a mano en 15 lugares: los 7 que preveía la especificación y 8 Resources más.

| Tema | Decisión |
|---|---|
| Esquema | Migración `000059`: `clients.kind` (`person` por defecto, así los existentes quedan como personas), `business_name` (150) y `address` (255) nulos, e índice en `business_name`. `first_name`/`last_name` pasan a nulos |
| Validación | `StoreClientRequest`: sin `kind`, persona como siempre. Empresa: `business_name` obligatorio y nombre y apellido `prohibited`; persona, al revés. `UpdateClientRequest`: el tipo efectivo es el enviado o el actual. Al cambiar de tipo, los campos del nuevo son obligatorios y `ClientService` vacía los del anterior |
| Cambio de tipo | Bloqueado con recepciones o facturas: `CLIENT_KIND_NOT_EDITABLE` (422, `ClientException`). Ya muestran el nombre del cliente |
| Nombre | `Client::display_name` reemplazó las 15 concatenaciones (factura, PDF, reportes, mantenimiento, historial y los Resources de recepción, vehículo, entrega, diagnóstico y avisos), sin cambiar las claves. Para personas el texto es idéntico: los tests existentes de esas áreas pasaron sin tocarlos |
| Saludo | `greeting_name`: el nombre de pila de una persona, como antes ("Hola, Ana"; decisión del usuario del 2026-10-29), y la razón social de una empresa |
| Búsquedas | `search` en clientes, vehículos, presupuestos, OT, recepciones y diagnósticos (solo el bloque del cliente; el del mecánico no cambia) y los filtros `first_name` (clientes) y `client_first_name` (vehículos) también buscan por razón social (decisión del usuario del 2026-10-29) |
| Baja (D9) | Pasó de `ClientController` a `ClientService::delete`. Con vehículos vigentes, recepciones (incluidas las eliminadas) o facturas responde `409 CLIENT_IN_USE` (decisión del usuario del 2026-10-29). **Cambio de comportamiento:** antes esa baja funcionaba |
| Seeder | `ClientsSeeder` agrega una empresa (`ClientsSeeder::COMPANY`, "Transportes del Este S.A.") sin vehículos ni recepciones. `DatabaseSeederTest` verifica los dos tipos |
| Pruebas | `tests/Feature/Clients/ClientKindTest` (7): alta de persona como antes, alta de empresa, validaciones por tipo, cambio de tipo con y sin recepciones, baja en uso, empresa mostrada y encontrada por razón social, y saludo |
| Fuera de alcance | El dígito verificador del RUC (V10), la baja del cliente de una aseguradora (M2) y el email único en la base (D7, decisión del usuario del 2026-10-29: queda para después) |
| Frontend | **No crear empresas en producción hasta adaptar `../synctaller-frontend`**: `first_name`/`last_name` como `string \| null`, `clientName()` con `display_name`, y el selector persona/empresa en el formulario. Para personas el contrato es el de antes, con campos agregados |

---

# 2.60 Aseguradoras (2026-10-29)

Entrega M2 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.2 y §9; decisión D). Reglas
vigentes en `CLAUDE.md`, 6.22.

| Tema | Decisión |
|---|---|
| Modelo | Migración `000060`: `insurers` (`client_id`, `code` `ASE-`, `status`, `payment_term_days`, `notes`, `created_by`, baja lógica) e `insurer_contacts` (`name`, `role`, `phone`, `email`, `is_primary`, `notes`). Los datos fiscales y de contacto de la aseguradora son los de su cliente empresa: no se duplican |
| Unicidad | Una aseguradora vigente por cliente, controlada en `InsurerService` y no con un índice único, para que una aseguradora dada de baja no retenga a su cliente (se puede volver a vincular) |
| Avisos (D) | Decisión del usuario del 2026-10-29: solo el cliente creado junto con la aseguradora nace con `notifications_enabled = false`. Un cliente existente conserva su configuración y la respuesta lo advierte en `meta.warnings`. Motivo: `notifications_enabled` es un interruptor único para los 6 avisos al cliente; desactivarlo a una empresa con vehículos propios le cortaría también esos avisos. Verificado en el código (`ClientNotificationService`): no afecta a los avisos internos |
| Alta | `client_id` o `client`, uno de los dos (`required_without` y `prohibits`). Los datos de `client` se validan con las reglas de `StoreClientRequest` con `kind = company`, sin duplicarlas; sus errores vuelven como `client.<campo>` |
| Código | `ASE-` es una serie nueva de `DocumentSequenceService` (E3): un caso más en `DocumentSeries`, sin migración propia |
| Fuera de alcance | `deductible_billing_mode` (decisión del usuario del 2026-10-29: se agrega en M10, en `insurers` y `claims`) y el bloqueo de la baja con siniestros (`INSURER_IN_USE`, M3) |
| Permisos | Los de la especificación (decisión del usuario del 2026-10-29): `insurers.index\|show` para recepcionista, jefe de taller y cajero; `insurers.store\|update\|destroy\|change-status` para recepcionista. Sin permisos propios para contactos |
| Cliente | `ClientService` bloquea la baja del cliente vinculado (`CLIENT_LINKED_TO_INSURER`, 409) y su cambio a persona (`CLIENT_KIND_NOT_EDITABLE`) |
| Seeder | `InsurerSeeder` (después de `ClientsSeeder`), por `InsurerService` con el recepcionista: "Seguros del Sur S.A." activa, con dos contactos, y "Aseguradora Guaraní S.A." inactiva. `DatabaseSeederTest` verifica los dos estados, el cliente empresa sin avisos y el contacto principal |
| Pruebas | `tests/Feature/Insurers/InsurerTest` (7): alta con cliente nuevo y existente (advertencia), validaciones, estados, contactos con un solo principal y binding anidado, protección del cliente y nuevo vínculo tras la baja, y permisos por rol |

---

# 2.61 Siniestros (2026-10-29)

Entrega M3 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.3, §5.2 y §9). Reglas
vigentes en `CLAUDE.md`, 6.23.

| Tema | Decisión |
|---|---|
| Modelo | Migración `000061`: `claims` con los campos de §4.3 (número y póliza, tipo, fecha y descripción, asegurado, perito, deducible, notas de cobertura, estado y datos de cierre y cancelación). `SIN-` es una serie más de `DocumentSequenceService` |
| Unicidad | Un siniestro vigente (no cancelado) por recepción, controlado en `ClaimService` (decisión del usuario del 2026-10-29): el cancelado queda en el historial y se puede abrir otro, por ejemplo con otra aseguradora. `Reception::currentClaim()` es el vigente |
| Asegurado | En uno propio, sin datos del asegurado se copian los del cliente de la recepción, con `insured_client_id` (decisión del usuario del 2026-10-29). En uno de tercero el nombre es obligatorio |
| Sincronización | `ClaimStatusService`, sin dependencias para evitar un ciclo (`ClaimService` usa `ReceptionStatusService`): `VehicleDeliveryService::deliver` lo cierra, `cancel` lo reabre y `ReceptionStatusService::cancel` lo cancela con el motivo de la recepción. Todo en la transacción del evento. Orden de bloqueo: recepción → siniestro |
| Edición | Solo `open` y con la recepción editable. Recepción, aseguradora y tipo son `prohibited`. El deducible se edita libremente hasta M9 (`CLAIM_DEDUCTIBLE_LOCKED` con facturas). La cancelación manual reutiliza `CancelReceptionRequest` (motivo obligatorio) |
| Aseguradora | La baja con siniestros, incluso cancelados, responde `INSURER_IN_USE` (409): completa lo que M2 dejó pendiente |
| Visibilidad | El detalle de la recepción agrega `claim` solo si el usuario tiene `claims.show` (decisión del usuario del 2026-10-29): el mecánico tiene `receptions.show`, pero no debe ver pólizas ni datos del asegurado (§8 de la especificación) |
| Permisos | `claims.index\|show` para recepcionista, jefe de taller y cajero; `claims.store\|update\|cancel` para recepcionista y jefe de taller. Mecánico y depósito sin acceso |
| Seeder | `ClaimSeeder` (después de `ReceptionSeeder`), con la aseguradora activa de `InsurerSeeder` y el recepcionista del escenario. Los tres nacen abiertos; el estado final sale de la sincronización: C07 abierto, H07 cerrado (se entrega) y X01 cancelado (se cancela la recepción) |
| Pruebas | `tests/Feature/Claims/ClaimTest` (8): asegurado copiado y visibilidad en la recepción, tercero, unicidades y nuevo siniestro tras cancelar, aseguradora inactiva y recepción cerrada, edición y cancelación, sincronización con entrega, anulación y cancelación de la recepción, aseguradora en uso y permisos |

---

# 2.62 Documentos privados de siniestros (2026-10-29)

Entrega M4 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.3, §8 y §9; decisión E).
Reglas vigentes en `CLAUDE.md`, 6.24.

| Tema | Decisión |
|---|---|
| Disco | `claim_documents` en `config/filesystems.php`: privado, `serve => false`, `throw => true`. `php artisan route:list --path=storage` sigue mostrando solo las 2 rutas del disco `local` (deuda #14): el disco nuevo no agrega ninguna |
| Tabla | Migración `000062`: `claim_documents` (`type`, `original_name`, `path` único, `mime`, `size`, `sha256`, `notes`, `uploaded_by`, `deleted_by`, `deletion_reason`, baja lógica). `insurer_authorization_id` se agrega en M6 (decisión del usuario del 2026-10-29) |
| Validación | `UploadClaimDocumentRequest`: `extensions` (nombre), `mimetypes` (contenido, por `finfo`) y una regla `after` que exige que la extensión coincida con el contenido (un PNG llamado `.pdf` se rechaza). 10 MB. Hallazgo: `UploadedFile::fake()` informa el tipo según la extensión del nombre, así que con fakes la validación por contenido pasa siempre; los tests usan archivos reales |
| Estado | Se suben documentos con el siniestro abierto o cerrado (decisión del usuario del 2026-10-29: el informe final del perito puede llegar después de la entrega); cancelado → `CLAIM_NOT_EDITABLE` |
| Descarga | `FileResponse::storedFileResponse` (nuevo): adjunto, tipo guardado y `nosniff`; el `fileResponse` de los PDF y CSV no cambió. Nombre saneado (sin comillas, barras ni caracteres de control). Evento de auditoría nuevo `AuditEvent::DOWNLOADED` (`downloaded`, "Descargado") con el siniestro, el tipo y el nombre. Archivo faltante en el disco → `CLAIM_DOCUMENT_FILE_MISSING` (404), sin registrar la descarga |
| Baja | Solo administrador (`claim-documents.destroy`), con motivo (`DestroyClaimDocumentRequest`). Lógica: el archivo queda en el disco hasta definir la retención (G10) |
| Auditoría | `ClaimDocument` es `Auditable` con `$auditExclude = ['path']`: la ruta interna no queda en `audit_logs` |
| Seeder | `ClaimDocumentSeeder` (después de `ClaimSeeder`): denuncia en PDF y foto PNG del siniestro abierto (C07), por el Service con el recepcionista. `DatabaseSeederTest` usa `Storage::fake('claim_documents')` y verifica los archivos |
| Pruebas | `tests/Feature/Claims/ClaimDocumentTest` (6): almacenamiento privado con UUID y sha256 (sin `path` en la API ni en la auditoría), rechazo de SVG, HTML, extensión que no coincide y más de 10 MB, descarga auditada como adjunto, baja (403 para el recepcionista, 404 después y archivo conservado), aislamiento por siniestro y estados, y permisos. Los helpers de los tests de siniestros pasaron a `ClaimTestCase` |

---

# 2.63 Responsable por línea del presupuesto (2026-10-29)

Entrega M5 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.4, §5.2 y §9). Reglas
vigentes en `CLAUDE.md`, 6.4.

| Tema | Decisión |
|---|---|
| Columna | Migración `000063`: `budget_items.responsibility` (`string(20)`, `owner` por defecto). Las líneas existentes quedaron a cargo del dueño sin migrar datos (en la base local, las 73) |
| Por defecto | `owner` aunque la recepción tenga siniestro (decisión del usuario del 2026-10-29): lo que paga la aseguradora se marca a propósito, y el frontend actual, que no manda el campo, no cambia nada |
| Regla | `insurer` exige un siniestro abierto en la recepción del presupuesto (`BUDGET_ITEM_INSURER_WITHOUT_CLAIM`, 422). Lo valida `BudgetService::ensureResponsibilityAllowed`, desde `BudgetItemController` (alta y edición) y desde el seeder. Solo en borrador, como el resto del ítem |
| Siniestro cancelado | `ClaimService::cancel` devuelve a `owner` las líneas `insurer` de los presupuestos **en borrador** de la recepción, uno por uno (queda en la auditoría de `BudgetItem`). Los enviados y aprobados no se tocan (decisión del usuario del 2026-10-29). La cancelación de la recepción ya cancela sus borradores |
| API | `BudgetItemResource` agrega `responsibility` (`value`/`label`). Aditivo |
| Seeder | `BudgetItemSeeder` carga a la aseguradora la línea del presupuesto del siniestro abierto (C07). `DatabaseSeederTest` verifica los dos valores |
| Pruebas | `tests/Feature/Budgets/BudgetItemResponsibilityTest` (4): dueño por defecto aun con siniestro, aseguradora solo con siniestro abierto (y valor inválido), solo en borrador, y vuelta al dueño al cancelar el siniestro (el enviado no cambia) |

# 2.64 Autorizaciones de la aseguradora (2026-10-29)

Entrega M6 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.5, P7 y decisión C).
Reglas vigentes en `CLAUDE.md`, 6.25.

| Tema | Decisión |
|---|---|
| Tablas | Migración `000064`: `insurer_authorizations` (única por `budget_id` + `version`), `insurer_authorization_items` y `claim_documents.insurer_authorization_id` (nulable, `restrictOnDelete`) |
| Unidad (P7) | Se guarda sin IVA, como el presupuesto. `amounts_include_tax` es obligatorio en cada registro (decisión del usuario del 2026-10-29): mientras V2 no tenga respuesta, quien carga dice en qué unidad vino el importe. Con IVA se convierte con la tasa del presupuesto (`invoice.default_tax_rate`) |
| Cobertura | Una línea por cada ítem `insurer`, ni más ni menos. Tope por importe y por cantidad (`INSURER_AUTHORIZATION_EXCEEDS_BUDGET`): lo que exceda va en un complementario (V1 sigue abierta) |
| Versiones | La nueva sustituye a la vigente. Si sube el importe de alguna línea, exige un documento del siniestro (decisión del usuario del 2026-10-29: evidencia obligatoria al subir); el documento queda vinculado y no se da de baja (`CLAIM_DOCUMENT_IN_USE`, 409) |
| Copia del ítem | Durante la implementación: `BudgetItemController::destroy` borra el ítem físicamente, y con `restrictOnDelete` borrar un ítem de un presupuesto reabierto habría dado 500. La línea guarda descripción, cantidad y total presupuestados, y la clave foránea pasa a `null` |
| Invalidación | `BudgetService::reopen` y `cancel` (ahora en una transacción) y `ReceptionStatusService::cancel` (por cada presupuesto que cancela). Cancelar el siniestro no la toca, igual que M5 con los presupuestos enviados |
| Sin dependencias | `InsurerAuthorizationService` no depende de otros Services: lo usan `BudgetService` y `ReceptionStatusService` |
| Detalle del presupuesto | `insurer_authorization` (vigente, resumida) solo con `insurer-authorizations.show`, como `claim` en la recepción. Aditivo |
| Seeder | C05 suma un siniestro abierto y sus líneas a la aseguradora: versión 1 parcial y versión 2 (sube la instalación, con el documento `authorization` como evidencia). C07: autorización total, invalidada al reabrir. `DatabaseSeederTest` verifica `current`, `superseded` e `invalidated` |
| Pruebas | `tests/Feature/Claims/InsurerAuthorizationTest` (7): registro por línea con conversión de IVA, coincidencia y topes, precondiciones, versiones y evidencia, invalidación (y borrado del ítem después de reabrir), inmutabilidad y permisos |

---

---

# 2.2 Autenticación

Existe:

```text
AuthController
LoginRequest
UserResource
```

El login se realiza mediante:

```http
POST /api/login
```

La autenticación utiliza:

```text
Laravel Sanctum
```

**Usuario autenticado (2026-10-21).** `GET /api/me`, bajo `auth:sanctum` y sin permiso específico, devuelve
`data.user` (mismo `UserResource` que el login) y `data.permissions` (nombres, ordenados). Motivo: el login solo trae
los IDs de los permisos de cada rol y no había forma de revalidar el token al recargar el frontend; con los nombres el
frontend adapta menús y botones sin duplicar el seeder. Al administrador se le devuelven todos los permisos del guard
`api`, coherente con el `Gate::before`. No se audita (es lectura). Tests: `tests/Feature/Auth/LoginTest`.

**Mecánico vinculado en `/me` (2026-10-23).** `GET /api/me` agrega `data.mechanic` (`id`, `employee_code`,
`full_name`), o `null` si el usuario no tiene ficha de mecánico. Motivo: el rol `mechanic` no tiene `mechanics.index`
y `GET /mechanics/{mechanic}/commissions` sin `mechanic-commissions.view-all` solo admite el propio mecánico, así que
el frontend (F10, comisiones) no tenía cómo conocer su `id`. Cambio aditivo; el login no cambia.

**Protección de roles y usuarios (2026-10-23).** Antes de la pantalla de administración del frontend (F12) la API
permitía eliminar el rol `administrador` o un rol con usuarios (que quedaban sin permisos), y que un administrador se
eliminara o se quitara el rol a sí mismo y perdiera el acceso. Ahora: `ROLE_PROTECTED` (422) al eliminar, renombrar o
cambiar el guard de `administrador` (el `Gate::before` lo reconoce por nombre); `ROLE_IN_USE` (409) al eliminar un
rol con usuarios; `USER_SELF_ACTION` (422) al eliminarse o cambiarse el propio rol. Tests en `tests/Feature/Access`.
En la misma rama: los mensajes de la regla `Password` (`mixed`, `numbers`, `symbols`, `letters`, `uncompromised`) salían
en inglés porque la regla registra la falla como `password.<regla>` y Laravel busca `password.password.<regla>`; se
corrigieron las claves en `StoreUserRequest` y `UpdateUserRequest`.

El login inválido devuelve una respuesta API estandarizada.

Ejemplo conceptual:

```json
{
    "success": false,
    "status": 401,
    "message": "Credenciales inválidas.",
    "data": null,
    "errors": null,
    "error_code": "UNAUTHENTICATED"
}
```

---

# 2.3 Roles y permisos

Se utiliza:

```text
spatie/laravel-permission
```

Roles principales:

```text
administrador
mechanic
recepcionista
jefe-taller
cajero
deposito
```

(El rol del mecánico se llama `mechanic` en el seeder y en el código; no `mecanico`.)

| Rol | Función | Accesos principales |
|---|---|---|
| `administrador` | Todo (`Gate::before`) | Exclusivo: usuarios/roles/permisos, `work-orders.view-economics`, liquidar comisiones, anular entregas, timbrados |
| `mechanic` | Ejecuta la OT | Diagnóstico, iniciar/completar OT e ítems, consumir/devolver repuestos, cargar sus horas, ver sus comisiones |
| `recepcionista` | Atiende al cliente | Clientes, vehículos, recepciones, checklist, presupuestos completos, registrar entregas; consulta OT, facturas y stock |
| `jefe-taller` | Organiza el trabajo | OT completas, ítems, repuestos en OT, asignación y horas de todos, diagnóstico, comisiones (consulta) |
| `cajero` | Cobra | Facturas, pagos, resumen de caja; consulta OT, presupuestos y timbrados |
| `deposito` | Maneja el stock | Repuestos, proveedores, inventario, repuestos en OT |

Los cuatro últimos se definen en `RolesAndPermissionsSeeder::workshopRoles()` (se sincronizan al volver a ejecutar el
seeder). `tests/Feature/Access/WorkshopRolesTest` verifica que todos sus permisos existan y qué puede y qué no puede
hacer cada rol. Ningún usuario del seeder tiene estos roles: se asignan desde `PATCH /api/users/{id}` (`role`).

Guard:

```text
api
```

Middleware configurados:

```text
role
permission
role_or_permission
```

Los Controllers modernos implementan:

```php
HasMiddleware
```

y definen permisos por operación.

> El rol `administrador` pasa todos los permisos por `Gate::before` (AppServiceProvider), por eso un permiso mal
> escrito en un controller no se nota con el admin. El nombre del middleware debe coincidir exactamente con el del
> `RolesAndPermissionsSeeder`; los tests con roles propios (`tests/Feature/Access`, `WorkOrderLifecycleTest`) lo verifican.

Ejemplo conceptual:

```text
work-orders.index
work-orders.store
work-orders.show
work-orders.update
work-orders.start
work-orders.pause
work-orders.resume
work-orders.complete
work-orders.cancel
```

---

# 2.4 Clientes

El módulo de clientes constituye una de las entidades principales del sistema.

Su función es identificar al propietario/responsable del vehículo y servir como origen del flujo de atención.

---

# 2.5 Vehículos

Existe el módulo de vehículos.

El vehículo se encuentra relacionado con el cliente y participa posteriormente en:

```text
Reception
Diagnostic
Budget
WorkOrder
```

Se implementaron:

```text
VehicleController
VehicleResource
ShowVehicleResource
StoreVehicleRequest
UpdateVehicleRequest
```

También existe contexto de:

```text
VehicleModel
```

para separar correctamente marca/modelo de la instancia concreta del vehículo.

Rutas anidadas por cliente propietario (complementan a `/api/vehicles`, que se mantiene):

```text
GET  /api/clients/{client}/vehicles             (vehicles.index)
POST /api/clients/{client}/vehicles             (vehicles.store)
GET  /api/clients/{client}/vehicles/{vehicle}   (vehicles.show)
```

Componentes: `ClientVehicleController` y `StoreClientVehicleRequest` (extiende `StoreVehicleRequest`;
el `client_id` se toma de la URL e ignora el del body). `scopeBindings()` devuelve 404 si el vehículo
pertenece a otro cliente. Editar y eliminar siguen solo en `/api/vehicles/{vehicle}`.

Decisión: la tabla se mantiene como `vehicles` (no `clients_vehicles`). El vehículo es una entidad propia
—eje de recepciones, presupuestos, OT e historial— y su dueño puede cambiar; un nombre compuesto
sugeriría una tabla pivote. Si en el futuro se necesita historial de propietarios, modelarlo aparte
(por ejemplo `vehicle_ownerships`).

---

# 2.6 Recepción

La recepción representa el ingreso del vehículo al taller.

Tiene relación con:

* cliente;
* vehículo;
* categorías de servicio;
* checklist;
* diagnóstico;
* presupuesto;
* orden de trabajo.

Existe una relación **many-to-many** entre Reception y ServiceCategory.

Esto permite que una recepción pueda solicitar múltiples categorías de servicio.

---

# 2.7 Checklist de recepción

La recepción puede generar un checklist basado en las categorías de servicio seleccionadas.

Se aplican restricciones de modificación según el estado de la recepción.

Estados relevantes incluyen:

```text
in_progress
completed
delivered
```

Una recepción que ya avanzó en el flujo no debe permitir modificaciones incompatibles con su estado.

Esta lógica fue revisada durante la auditoría de excepciones.

> **Verificado (v1.2).** `ReceptionStatus` tiene `pending, in_progress, repair, completed, delivered, cancelled`.
> Ningún endpoint asignaba `in_progress`, `repair`, `completed` ni `cancelled`: la recepción nacía `pending` y solo la
> entrega (FASE 17) la pasaba a `delivered`. Ver sección 14.

## Estados de la recepción (2026-10-19)

Decisión: el estado **se deriva del flujo** en lugar de asignarse con un endpoint por transición, para que nunca quede
desincronizado de las OT. Solo la entrega (`delivered`) y la cancelación manual (`cancelled`) lo fijan.

```text
pending      --crear presupuesto-->        in_progress  (En proceso)
in_progress  --iniciar/reanudar una OT-->  repair       (En reparación; también con la OT pausada)
repair       --completar la(s) OT-->       completed    (Terminado: todas completadas o canceladas)
completed    --nueva OT (trabajo extra)--> in_progress
completed    --entregar-->                 delivered    (FASE 17; anular la entrega vuelve al estado anterior)
pending|in_progress|completed --cancelar (sin OT vigentes)--> cancelled
```

* `ReceptionStatusService::sync()` recalcula: alguna OT `in_progress|paused` → `repair`; alguna `pending` →
  `in_progress`; completadas (resto canceladas) → `completed`; sin OT vigentes → `in_progress` con presupuesto o
  `pending` sin él. Se llama al crear/eliminar presupuestos y en cada cambio de estado de una OT.
* Se eligió **crear el presupuesto** (y no el diagnóstico) como inicio del proceso: el checklist puede completarse
  durante el diagnóstico; al presupuestar se congela.
* "Procesada" = todo salvo `pending` (antes `in_progress|completed|delivered`, sin `repair` ni `cancelled`).
* `IN_PROGRESS` pasó a mostrarse "En proceso" (antes "En reparación", igual que `REPAIR`); el `value` no cambió.
* Cancelación (`receptions.cancel`, recepcionista y administrador): motivo obligatorio; columnas `cancelled_at`,
  `cancelled_by`, `cancellation_reason` (migración 000045, que además recalcula en SQL las recepciones existentes).
* Recepción cerrada: diagnósticos, presupuestos y OT nuevos → `RECEPTION_CLOSED`.

---

# 2.8 Diagnóstico

El diagnóstico permite registrar la evaluación técnica realizada sobre el vehículo.

El diagnóstico tiene relación con el mecánico responsable.

Existe:

```text
mechanic_id
```

como referencia al mecánico.

Esto prepara el sistema para posteriormente utilizar información del diagnóstico como entrada para el presupuesto.

---

# 2.9 Mecánicos

El módulo de mecánicos contempla información como:

```text
employee_code
user_id
specialty
hour_cost
commission_percentage
status
```

El estado del mecánico está representado mediante Enum.

El módulo permitirá posteriormente utilizar el costo/hora y otros datos para cálculo de mano de obra.

---

# 2.10 Categorías de servicio

Las categorías de servicio representan tipos generales de trabajos solicitados en el taller.

Participan principalmente en:

```text
Reception
Checklist
Budget
WorkOrder
```

La arquitectura utiliza categorías en lugar de codificar directamente todos los servicios dentro de Recepción.

---

# 2.11 Presupuestos

El módulo Budget está funcional.

Principales componentes:

```text
Budget
BudgetItem
BudgetService
BudgetException
BudgetStatus
BudgetItemType
BudgetController
BudgetResource
ShowBudgetResource
StoreBudgetRequest
UpdateBudgetRequest
```

---

## Estados de Budget

Actualmente:

```text
draft
sent
approved
rejected
cancelled
```

Flujo:

```text
draft
   ↓
sent
   ↓
approved
```

También:

```text
sent → rejected
sent → cancelled
draft → cancelled

rejected → draft
```

> **Verificado (v1.2):** `BudgetService::cancel()` acepta `draft` y `sent` (test `BudgetLifecycleTest`). `approved`,
> `rejected` y `cancelled` no se cancelan (`BUDGET_NOT_CANCELLABLE`).

---

## Reglas importantes

Un presupuesto:

* puede editarse únicamente en `draft`;
* puede enviarse;
* puede aprobarse únicamente cuando cumple las condiciones necesarias;
* puede rechazarse;
* puede cancelarse;
* puede reabrirse desde rechazado.

Para aprobar:

* debe tener items;
* se registra `approved_at`.

---

# 2.12 BudgetItem

Los tipos actuales son:

```text
labor
part
service
```

Los BudgetItems poseen:

```text
quantity
unit_price
total
```

Esto es importante para el futuro módulo de repuestos.

Actualmente el precio económico está correctamente representado en el presupuesto.

---

# 2.13 Orden de Trabajo

El módulo de WorkOrder está completamente desarrollado para el alcance actual.

Componentes principales:

```text
WorkOrder
WorkOrderItem
WorkOrderService
WorkOrderException
WorkOrderController
WorkOrderItemController
WorkOrderResource
ShowWorkOrderResource
WorkOrderItemResource
```

---

# 2.14 Creación de WorkOrder

Una orden de trabajo se crea desde un presupuesto aprobado.

Regla:

```text
Budget.status === approved
```

Además:

* el presupuesto debe tener al menos un item;
* el presupuesto no debe tener una WorkOrder existente.

La orden recibe un código:

```text
OT-000001
OT-000002
OT-000003
...
```

---

# 2.15 Estados de WorkOrder

El flujo actual es:

```text
pending
   ↓
in_progress
   ↓
completed
```

También existe:

```text
pending → cancelled
```

Y operaciones:

```text
in_progress → paused
paused → in_progress
```

El sistema evita transiciones inválidas.

> **Verificado (v1.2):** `WorkOrderStatus` definía también `waiting_parts` y `delivered`, pero ninguna operación los
> asignaba. **Eliminados el 2026-10-19** (deuda técnica #3): el enum queda `pending`, `in_progress`, `paused`,
> `completed` y `cancelled`. La entrega del vehículo (FASE 17) no cambia la OT: queda `completed`, porque el cierre
> económico, la facturación y los reportes dependen de ese estado. Si "esperando repuestos" se necesita, se diseña
> como transición propia (hoy lo cubren la pausa y las líneas de repuestos `pending`).

---

# 2.16 WorkOrderItem

Los items de la orden de trabajo derivan de BudgetItems.

Tipos:

```text
labor
part
service
```

Estados:

```text
pending
in_progress
completed
cancelled
```

Reglas principales:

* solamente se puede iniciar un item cuando la WorkOrder está `in_progress`;
* un item `pending` puede pasar a `in_progress`;
* un item `in_progress` puede completarse;
* un item pendiente puede cancelarse;
* el item debe pertenecer a la WorkOrder indicada.

---

# 2.17 Importante: WorkOrderItem no es actualmente inventario

Una decisión importante para futuras fases:

`WorkOrderItem` actualmente representa el trabajo/material incluido en la orden, pero **no es todavía un sistema de inventario de repuestos**.

Actualmente no almacena:

```text
unit_price
total
stock
warehouse
supplier
purchase cost
sale price
```

Los precios sí existen en:

```text
BudgetItem
```

Esto debe tenerse en cuenta al implementar el futuro módulo de repuestos.

---

# 2.18 Edición de WorkOrder

La WorkOrder solamente puede editarse mientras está:

```text
pending
```

La edición actual permite modificar principalmente:

```text
mechanic_id
notes
```

No debe permitirse alterar arbitrariamente:

```text
code
reception_id
budget_id
created_by
status
timestamps
```

---

# 2.19 Flujo completo validado

Se realizó una regresión completa del flujo:

```text
Budget
   ↓
Approved
   ↓
WorkOrder
   ↓
Start
   ↓
Start Items
   ↓
Complete Items
   ↓
Complete WorkOrder
```

Se validaron casos reales con órdenes:

```text
OT-000003
OT-000004
```

También se verificó que una orden completada no pueda reiniciarse.

---

# 2.20 API Response

Se implementó un contrato global.

## Éxito

Formato conceptual:

```json
{
    "success": true,
    "status": 200,
    "message": "Operación realizada correctamente.",
    "data": {},
    "errors": null,
    "meta": null,
    "timestamp": "..."
}
```

`meta` es `null` salvo en listados (`meta.pagination` con `total`, `perPage`, `currentPage`, `lastPage`) y algunos
reportes (por ejemplo `meta.counts` en alertas de mantenimiento). Fuente: `App\Traits\ApiResponse`.

## Error

Formato actual:

```json
{
    "success": false,
    "status": 403,
    "message": "No tienes permisos para realizar esta acción.",
    "data": null,
    "errors": null,
    "error_code": "FORBIDDEN"
}
```

El campo:

```text
error_code
```

es importante para que el frontend pueda reaccionar programáticamente.

---

# 2.21 Excepciones

Jerarquía: ver sección 1.4 (una subclase de `AppException` por módulo: Budget, WorkOrder, WorkOrderPart, Part,
Supplier, Inventory, Service, Invoice, Payment y VehicleDelivery).

`AppException` recibe:

```text
message
status
errorCode
errors
```

Conceptualmente:

```php
AppException(
    message,
    status,
    errorCode,
    errors
)
```

---

# 2.22 Códigos HTTP estandarizados

La auditoría de excepciones estableció respuestas para:

```text
401 UNAUTHENTICATED
403 FORBIDDEN
404 RESOURCE_NOT_FOUND
405 METHOD_NOT_ALLOWED
422 VALIDATION_ERROR
500 INTERNAL_SERVER_ERROR
503 SERVICE_UNAVAILABLE
```

También se trabajó el manejo de excepciones de infraestructura y HTTP.

> **Verificado (v1.2)** en `bootstrap/app.php`: además se mapean `400 BAD_REQUEST`, `406 NOT_ACCEPTABLE`,
> `415 UNSUPPORTED_MEDIA_TYPE` y `429 TOO_MANY_REQUESTS`. Los errores de dominio usan `422` o `409` (conflictos:
> duplicados, ya facturada, inmutables). Todo responde JSON solo si el request espera JSON (`Accept: application/json`).
> Desde el 2026-10-19 el middleware global `App\Http\Middleware\EnsureJsonResponse` fija ese header en toda ruta
> `api/*`, antes del ruteo (así también los 404/405 responden JSON); las rutas web no se tocan.

---

# 2.23 FASE 9 — Auditoría de excepciones

La auditoría avanzó por:

```text
9.1  Auditoría inicial
9.2  Definición del contrato
9.3  AppException
9.4  BudgetException
9.5  WorkOrderException
9.6  Transformación HTTP
9.7  Dominio / infraestructura
9.8  Validation
9.9  Authentication
9.10 Not Found
9.11 HTTP Status Codes
9.12 Error Codes
9.13 Integración de excepciones
9.14 Auditoría final de Controllers
```

La fase 9.14 quedó cerrada.

---

# 3. Decisiones de diseño y convenciones

# 3.1 No colocar lógica de negocio compleja en Controllers

Incorrecto:

```text
Controller
 ├── validaciones de negocio
 ├── cambios de estado
 ├── transacciones
 ├── creación de múltiples modelos
 └── reglas complejas
```

Preferido:

```text
Controller
    ↓
Service
    ↓
Models
```

---

# 3.2 Services para lógica de negocio

Los Services son obligatorios para procesos que:

* cambian estados;
* modifican varias entidades;
* requieren transacciones;
* contienen reglas de negocio;
* representan operaciones del dominio.

Ejemplo:

```text
WorkOrderService
```

con operaciones:

```text
createFromBudget()
start()
pause()
resume()
complete()
cancel()
startItem()
completeItem()
cancelItem()
update()
updateItem()
```

---

# 3.3 Transacciones

Las operaciones que afectan múltiples registros deben utilizar:

```php
DB::transaction(...)
```

Esto es especialmente importante para:

```text
crear WorkOrder + items
cancelar WorkOrder + items
operaciones sobre Budget
operaciones futuras de inventario
```

---

# 3.4 Estados mediante Enum

No introducir strings arbitrarios cuando existe un estado formal.

Ejemplo:

```php
BudgetStatus::APPROVED
```

en lugar de:

```php
'approved'
```

La persistencia sigue usando los valores correspondientes, pero la lógica PHP debe preferir Enums.

---

# 3.5 Naming

Modelos:

```text
PascalCase singular
```

Ejemplo:

```text
WorkOrder
BudgetItem
Vehicle
```

Tablas:

```text
snake_case plural
```

Ejemplo:

```text
work_orders
budget_items
```

Métodos:

```text
camelCase
```

Ejemplo:

```text
createFromBudget()
startItem()
completeItem()
```

Rutas:

```text
kebab-case
```

Ejemplo:

```text
/api/work-orders
```

---

# 3.6 Resources

Los Resources controlan la representación externa de las entidades.

No exponer directamente:

```php
$model->toArray()
```

como contrato público si existe un Resource.

---

# 3.7 Listado vs detalle

Se mantiene la separación:

```text
Resource
```

para listados.

```text
ShowResource
```

para detalles.

Ejemplo:

```text
BudgetResource
ShowBudgetResource
```

---

# 3.8 Nested routes

Se utiliza:

```php
scopeBindings()
```

para rutas anidadas.

Esto permite validar correctamente relaciones como:

```text
work-order → item
```

y evita aceptar un item perteneciente a otra orden.

---

# 3.9 PATCH para actualizaciones

Las actualizaciones utilizan:

```http
PATCH
```

cuando conceptualmente son parciales.

No cambiar esta convención sin una razón arquitectónica clara.

---

# 3.10 Middleware de permisos

Los Controllers utilizan:

```php
HasMiddleware
```

con permisos específicos.

Ejemplo conceptual:

```text
work-orders.index
work-orders.store
work-orders.show
work-orders.update
work-orders.start
work-orders.pause
work-orders.resume
work-orders.complete
work-orders.cancel
```

No reemplazar esto por una autorización global indiscriminada.

---

# 3.11 Contrato de errores

Todos los errores API deben utilizar el formato:

```json
{
    "success": false,
    "status": 422,
    "message": "...",
    "data": null,
    "errors": null,
    "error_code": "..."
}
```

No devolver directamente:

```text
exception
file
line
trace
```

en producción.

---

# 3.12 Error codes

Los mensajes son para humanos.

Los `error_code` son para el frontend.

Ejemplos:

```text
VALIDATION_ERROR
UNAUTHENTICATED
FORBIDDEN
RESOURCE_NOT_FOUND
METHOD_NOT_ALLOWED
SERVICE_UNAVAILABLE
```

Y códigos de dominio específicos:

```text
WORK_ORDER_NOT_CANCELLABLE
```

El frontend debe poder tomar decisiones utilizando `error_code`, no haciendo parsing del texto de `message`.

---

# 3.13 Mensajes

Los mensajes visibles de la API están en español.

Los nombres técnicos del código permanecen en inglés.

Ejemplo:

```text
Código:
WORK_ORDER_NOT_CANCELLABLE

Mensaje:
La orden de trabajo solo puede cancelarse cuando está pendiente.
```

---

# 3.14 No sobre-refactorizar

Una decisión importante durante el proyecto fue:

> No refactorizar arquitectura existente solamente por estilo.

Si una implementación funciona y respeta las convenciones actuales, debe mantenerse salvo que exista una razón funcional, de seguridad, rendimiento o mantenibilidad.

---

# 4. Variables de entorno y configuración

Nunca migrar secretos reales dentro del handoff.

> **Nota (verificado el 2026-10-19):** esta sección es una plantilla histórica. Los valores de referencia vigentes están
> en `.env.example` (por ejemplo `APP_URL=http://127.0.0.1:8000` y `ADMIN_PASSWORD`, la contraseña de los usuarios del
> seeder). `VITE_API_URL` pertenece al repositorio del frontend: este repositorio no la usa.

El nuevo entorno debe disponer de variables equivalentes a:

```env
APP_NAME=SyncTaller
APP_ENV=local
APP_KEY=<SECRET>
APP_DEBUG=true
APP_URL=http://localhost
```

Base de datos:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=synctaller
DB_USERNAME=<DB_USER>
DB_PASSWORD=<DB_PASSWORD>
```

Sesiones/cache/colas según configuración de Laravel:

```env
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Mail:

```env
MAIL_MAILER=<MAILER>
MAIL_HOST=<MAIL_HOST>
MAIL_PORT=<MAIL_PORT>
MAIL_USERNAME=<MAIL_USERNAME>
MAIL_PASSWORD=<MAIL_PASSWORD>
MAIL_ENCRYPTION=<MAIL_ENCRYPTION>
MAIL_FROM_ADDRESS=<MAIL_FROM>
MAIL_FROM_NAME="${APP_NAME}"
```

Frontend:

```env
VITE_API_URL=<BACKEND_API_URL>
```

Los valores concretos deben configurarse en cada entorno.

---

# 4.1 Archivos que NO deben migrarse con secretos

No incluir públicamente:

```text
.env
```

ni:

```text
API keys
passwords
tokens
APP_KEY
credenciales SMTP
credenciales de base de datos
tokens de terceros
```

Debe migrarse:

```text
.env.example
```

con valores ficticios o vacíos.

---

# 4.2 Dependencias PHP relevantes

Entre las dependencias importantes:

```text
laravel/framework
laravel/sanctum
spatie/laravel-permission
```

Además de las dependencias normales de Laravel/Composer.

Antes de continuar el desarrollo en otra herramienta:

```bash
composer install
```

y:

```bash
npm install
```

> **Nota (verificado el 2026-10-19):** `npm install` es **opcional** en este repositorio: `package.json` solo trae el
> scaffold de Vite + Tailwind de Laravel, que la API no usa (`composer setup` y `composer dev` sí lo invocan). Ver
> README, "Requisitos".

---

# 5. Próximos módulos / Roadmap

> **Nota histórica:** esta sección es el roadmap original del handoff. Los módulos de repuestos, inventario,
> servicios, cierre económico, facturación, pagos y entrega (5.1–5.15) ya están implementados (FASES 10–17,
> secciones 2.24–2.30). Lo pendiente empieza en 5.16 (historial del vehículo); el punto exacto está en la sección 11.

## Diseño original del módulo de Repuestos (implementado en FASES 10–12)

No debía implementarse simplemente como un CRUD de `repuestos`, sino como un pequeño módulo de inventario
integrado con la WorkOrder.

---

# 5.1 Concepto recomendado

Separar:

```text
Repuesto / Producto
```

de:

```text
Movimiento de inventario
```

y de:

```text
Repuesto utilizado en WorkOrder
```

Esto evita mezclar catálogo, stock y consumo.

Arquitectura recomendada:

```text
Part
   │
   ├── Stock
   │
   ├── Movements
   │
   └── WorkOrder usage
```

---

# 5.2 Catálogo de repuestos

Entidad futura:

```text
Part
```

Información sugerida:

```text
id
code
sku
name
description
brand
part_number
category_id
unit
cost_price
sale_price
minimum_stock
status
```

Debe permitir identificar un repuesto independientemente de una WorkOrder.

---

# 5.3 Categorías de repuestos

Entidad futura:

```text
PartCategory
```

Ejemplos:

```text
Filtros
Frenos
Lubricantes
Suspensión
Motor
Transmisión
Eléctrico
Refrigeración
Neumáticos
```

---

# 5.4 Proveedores

Futuro módulo:

```text
Supplier
```

Permitirá relacionar:

```text
Supplier
   ↓
Part
```

Posteriormente permitirá compras.

---

# 5.5 Inventario

No se recomienda guardar simplemente:

```text
parts.stock
```

sin historial.

Se recomienda implementar:

```text
Inventory
InventoryMovement
```

o una arquitectura equivalente.

Los movimientos pueden ser:

```text
purchase
sale
work_order
adjustment
return
transfer
```

Cada movimiento debería registrar:

```text
part_id
quantity
type
reference
unit_cost
created_by
created_at
```

---

# 5.6 Consumo de repuestos en WorkOrder

Una WorkOrder debe poder registrar:

```text
repuesto utilizado
cantidad
precio
costo
mecánico/responsable
fecha
```

Pero conviene **no reutilizar ciegamente WorkOrderItem** para esto.

La recomendación profesional es separar:

```text
WorkOrderItem
```

de:

```text
WorkOrderPart
```

o una entidad equivalente.

Motivo:

`WorkOrderItem` representa actualmente el trabajo derivado del presupuesto.

Un consumo de inventario tiene características diferentes:

```text
stock
cost
sale_price
quantity_used
inventory movement
```

---

# 5.7 Flujo recomendado de repuestos

```text
Budget
   ↓
BudgetItem(type = part)
   ↓
WorkOrder
   ↓
WorkOrderPart
   ↓
Stock reservation / consumption
   ↓
InventoryMovement
   ↓
Stock updated
```

Esto permitirá que el presupuesto indique qué repuesto se presupuestó, mientras que la WorkOrder registra qué repuesto realmente se utilizó.

---

# 5.8 Diferencia entre presupuestado y utilizado

Este punto es fundamental.

Ejemplo:

Presupuesto:

```text
2 litros de aceite
```

Trabajo realizado:

```text
1.7 litros utilizados
```

El sistema debería poder distinguir:

```text
presupuestado
utilizado
facturado
```

No asumir que:

```text
BudgetItem.quantity === WorkOrderPart.quantity
```

---

# 5.9 Reserva de stock

En una versión profesional se puede incorporar:

```text
available_stock
reserved_stock
```

Flujo:

```text
Presupuesto aprobado
        ↓
WorkOrder creada
        ↓
Reservar repuestos
        ↓
Trabajo ejecutado
        ↓
Consumir stock
```

Pero esta funcionalidad puede implementarse después del MVP de inventario.

---

# 5.10 Compras

Después del inventario:

```text
Purchase
PurchaseItem
Supplier
```

Flujo:

```text
Proveedor
   ↓
Compra
   ↓
Ingreso de stock
   ↓
InventoryMovement
```

---

# 5.11 Módulo de servicios

Debe existir eventualmente un catálogo formal:

```text
Service
ServiceCategory
```

con:

```text
name
description
standard_price
estimated_hours
status
```

Esto permitirá presupuestos más consistentes.

---

# 5.12 Mano de obra

El sistema ya contempla:

```text
Mechanic.hour_cost
Mechanic.commission_percentage
```

El siguiente nivel será calcular:

```text
horas estimadas
horas reales
costo mano de obra
precio de venta
comisión
```

Esto permitirá obtener rentabilidad real de una WorkOrder.

---

# 5.13 Facturación

Después de cerrar el flujo operativo:

```text
WorkOrder
   ↓
Finalización
   ↓
Factura / Invoice
```

Debe diferenciarse:

```text
Budget
```

de:

```text
Invoice
```

El presupuesto es una propuesta.

La factura representa una obligación económica real.

---

# 5.14 Pagos

Posteriormente:

```text
Payment
```

Permitirá:

```text
cash
card
transfer
other
```

y:

```text
partial payment
full payment
```

---

# 5.15 Entrega del vehículo

Flujo futuro:

```text
WorkOrder completed
        ↓
Invoice
        ↓
Payment
        ↓
Vehicle delivered
```

La entrega debería quedar registrada formalmente.

---

# 5.16 Historial del vehículo

Uno de los módulos de mayor valor para el taller será:

```text
VehicleHistory
```

Debe permitir consultar:

```text
recepciones
diagnósticos
presupuestos
órdenes de trabajo
reparaciones
repuestos utilizados
kilometraje
costos
```

Esto permitirá mostrar al usuario:

> Historial completo del vehículo.

---

# 5.17 Mantenimiento preventivo

Posteriormente:

```text
MaintenanceSchedule
```

Ejemplos:

```text
Cambio de aceite
Rotación de neumáticos
Cambio de filtros
Distribución
Revisión de frenos
```

Basado en:

```text
kilometraje
fecha
tiempo transcurrido
```

---

# 5.18 Notificaciones

Futuro módulo:

```text
Notification
```

Eventos posibles:

```text
presupuesto enviado
presupuesto aprobado
vehículo listo
pago pendiente
mantenimiento próximo
```

---

# 5.19 Reportes

Futuro módulo:

```text
Reports
```

Reportes recomendados:

```text
ventas
presupuestos
presupuestos aprobados
órdenes completadas
órdenes canceladas
repuestos consumidos
stock bajo
rentabilidad
productividad por mecánico
```

---

# 5.20 Auditoría

A medida que el sistema crezca será recomendable implementar:

```text
AuditLog
```

para registrar:

```text
usuario
acción
entidad
registro
valor anterior
valor nuevo
fecha
IP
```

Especialmente para:

```text
precios
stock
presupuestos
facturación
pagos
```

---

# 6. Roadmap recomendado por fases

## FASE 10 — Catálogo de repuestos

```text
10.1 Part
10.2 PartCategory
10.3 marcas / fabricantes
10.4 unidades de medida
10.5 CRUD de repuestos
10.6 permisos
10.7 Resources
10.8 Requests
10.9 validaciones
10.10 excepciones
```

---

## FASE 11 — Inventario

```text
11.1 Inventory
11.2 InventoryMovement
11.3 entradas
11.4 salidas
11.5 ajustes
11.6 stock mínimo
11.7 stock disponible
11.8 historial
11.9 permisos
11.10 auditoría
```

---

## FASE 12 — Repuestos en WorkOrder

```text
12.1 WorkOrderPart
12.2 agregar repuesto
12.3 quitar repuesto
12.4 modificar cantidad
12.5 reservar
12.6 consumir
12.7 devolver
12.8 sincronización con inventario
12.9 costo
12.10 precio de venta
```

---

## FASE 13 — Catálogo de servicios y mano de obra

```text
13.1 Service
13.2 ServiceCategory
13.3 precio estándar
13.4 tiempo estimado
13.5 horas reales
13.6 costo de mecánico
13.7 comisión
```

---

## FASE 14 — Cierre económico de WorkOrder

```text
14.1 cálculo de costo
14.2 cálculo de venta
14.3 margen
14.4 diferencias presupuesto vs realidad
14.5 rentabilidad
```

---

## FASE 15 — Facturación

```text
15.1 Invoice
15.2 InvoiceItem
15.3 numeración
15.4 impuestos
15.5 descuentos
15.6 total
15.7 estados
```

---

## FASE 16 — Pagos

```text
16.1 Payment
16.2 métodos de pago
16.3 pagos parciales
16.4 saldo
16.5 conciliación
```

---

## FASE 17 — Entrega

```text
17.1 VehicleDelivery
17.2 comprobante
17.3 kilometraje final
17.4 observaciones
17.5 firma/aceptación
```

---

## FASE 18 — Historial del vehículo

```text
18.1 historial
18.2 reparaciones
18.3 repuestos
18.4 servicios
18.5 costos
18.6 kilometraje
```

---

## FASE 19 — Mantenimiento preventivo

```text
19.1 MaintenancePlan
19.2 intervalos
19.3 kilometraje
19.4 fechas
19.5 alertas
```

---

## FASE 20 — Reportes y dashboard

```text
20.1 dashboard
20.2 ventas
20.3 rentabilidad
20.4 productividad
20.5 inventario
20.6 clientes
20.7 vehículos
```

---

# 7. Memoria técnica relevante

## 7.1 No confundir BudgetItem con WorkOrderItem

Actualmente:

```text
BudgetItem
```

maneja información económica:

```text
quantity
unit_price
total
```

Mientras:

```text
WorkOrderItem
```

representa ejecución del trabajo.

No asumir que ambas entidades deben permanecer idénticas.

---

# 7.2 No convertir WorkOrderItem en inventario

Esta es una decisión arquitectónica importante.

El futuro inventario debe tener sus propias entidades.

Recomendado:

```text
Part
Inventory
InventoryMovement
WorkOrderPart
```

en lugar de sobrecargar:

```text
WorkOrderItem
```

---

# 7.3 Presupuesto aprobado como origen de WorkOrder

Actualmente una WorkOrder nace desde:

```text
Budget.status = approved
```

No romper esta regla sin rediseñar explícitamente el flujo.

---

# 7.4 Una sola WorkOrder por Budget

Actualmente se evita crear múltiples WorkOrders para el mismo presupuesto.

Esta regla protege la integridad del flujo:

```text
Budget
   ↓
WorkOrder
```

---

# 7.5 WorkOrder completada no vuelve atrás

Una orden:

```text
completed
```

no debe:

```text
start
pause
resume
```

ni regresar a:

```text
pending
```

Esto fue probado explícitamente.

---

# 7.6 Cancelación

Una WorkOrder solamente puede cancelarse mientras está:

```text
pending
```

La cancelación produce también el estado correspondiente de sus items pendientes/en progreso.

---

# 7.7 Edición

La WorkOrder es editable solamente cuando está:

```text
pending
```

Una vez iniciado el trabajo, las modificaciones deben tratarse como operaciones específicas y no como edición libre.

---

# 7.8 Scope bindings

Mantener:

```php
scopeBindings()
```

para relaciones anidadas.

Es especialmente importante para:

```text
WorkOrder → WorkOrderItem
```

---

# 7.9 Excepciones

No volver al patrón de devolver:

```text
500
```

para errores de negocio conocidos.

Ejemplo:

```text
No se puede cancelar una WorkOrder completada
```

es un error de negocio y debe responder:

```text
422
```

con su correspondiente `error_code`.

---

# 7.10 Seguridad

No exponer en producción:

```text
stack trace
archivo
línea
clase de excepción
```

Los errores deben transformarse al contrato público de la API.

---

# 7.11 Validación vs regla de negocio

Separar:

```text
Request validation
```

de:

```text
Business validation
```

Ejemplo:

```text
budget_id debe ser entero
```

→ Request.

Mientras:

```text
El presupuesto debe estar aprobado para crear una WorkOrder
```

→ Service / dominio.

---

# 7.12 Integridad de estados

Los estados deben tratarse como una máquina de estados implícita.

No permitir transiciones arbitrarias.

Antes de agregar un nuevo estado, documentar:

```text
estado actual
    ↓
acción
    ↓
estado nuevo
```

---

# 7.13 API pública

El frontend debe depender del contrato de la API:

```text
success
status
message
data
errors
error_code
```

No debe depender de:

```text
estructura interna de Exceptions
nombres de clases PHP
SQL
stack traces
```

---

# 7.14 No hacer refactors innecesarios al migrar

Al migrar el proyecto a otra herramienta, primero conseguir:

```text
backend funcionando
↓
tests/regresión
↓
misma API
↓
misma base de datos
↓
recién después nuevas funcionalidades
```

No aprovechar la migración para cambiar simultáneamente:

```text
arquitectura
naming
API
base de datos
framework
```

porque dificultaría detectar regresiones.

---

# 8. Checklist para continuar en otra herramienta

> Plantilla para configurar el proyecto en una máquina nueva (no es un estado del proyecto). Los pasos concretos de
> instalación están en el [`README.md`](../README.md). La sección "Frontend" aplica al repositorio del frontend, que no
> forma parte de este.

## Backend

```text
[ ] Laravel 12 instalado
[ ] PHP compatible
[ ] Composer instalado
[ ] MySQL configurado
[ ] .env configurado
[ ] APP_KEY generado
[ ] migrations ejecutadas
[ ] seeders ejecutados
[ ] Sanctum funcionando
[ ] Spatie Permission funcionando
[ ] API respondiendo
```

## Frontend

```text
[ ] Node.js instalado
[ ] npm install
[ ] VITE_API_URL configurada
[ ] login funcionando
[ ] token/session funcionando
[ ] consumo de API funcionando
```

## Validación

```text
[ ] Login
[ ] Permissions
[ ] Client
[ ] Vehicle
[ ] Reception
[ ] Diagnostic
[ ] Budget
[ ] BudgetItem
[ ] WorkOrder
[ ] WorkOrderItem
[ ] Exceptions
[ ] HTTP status codes
```

---

# 9. Regla principal para el nuevo desarrollador/agente

Antes de implementar cualquier módulo nuevo:

1. Leer este documento completo.
2. Revisar las entidades existentes.
3. Revisar los Enums.
4. Revisar Services.
5. Revisar Resources.
6. Revisar Requests.
7. Revisar permisos.
8. Revisar excepciones.
9. Revisar rutas existentes.
10. No modificar contratos existentes sin justificarlo.

---

# 10. Principio arquitectónico futuro

El sistema debe evolucionar desde:

```text
Presupuestador
```

hacia:

```text
Sistema integral de gestión de taller
```

La arquitectura objetivo es:

```text
                    ┌──────────────┐
                    │    Cliente   │
                    └──────┬───────┘
                           │
                    ┌──────▼───────┐
                    │   Vehículo   │
                    └──────┬───────┘
                           │
                    ┌──────▼───────┐
                    │  Recepción   │
                    └──────┬───────┘
                           │
             ┌─────────────┼─────────────┐
             │             │             │
       Diagnóstico    Presupuesto     Checklist
             │             │
             │        ┌────▼─────┐
             │        │ Aprobado │
             │        └────┬─────┘
             │             │
             │       ┌─────▼──────┐
             └──────►│ WorkOrder  │
                     └─────┬──────┘
                           │
              ┌────────────┼────────────┐
              │            │            │
           Servicios     Mano obra   Repuestos
              │            │            │
              │            │       ┌────▼──────┐
              │            │       │ Inventario│
              │            │       └────┬──────┘
              │            │            │
              └────────────┼────────────┘
                           │
                    ┌──────▼───────┐
                    │   Cierre     │
                    └──────┬───────┘
                           │
                    ┌──────▼───────┐
                    │  Facturación │
                    └──────┬───────┘
                           │
                    ┌──────▼───────┐
                    │    Pago      │
                    └──────┬───────┘
                           │
                    ┌──────▼───────┐
                    │   Entrega    │
                    └──────────────┘
```

---

# 11. Punto exacto donde continuar

El proyecto actualmente se encuentra en:

```text
FASE 25 — AUDITORÍA AVANZADA — IMPLEMENTADA (2026-10-20, ver 2.38)
```

Con la FASE 20 se completó el roadmap original (FASES 10–20); después se implementaron compras (FASE 21), pagos a
proveedores (FASE 22), notificaciones por email y bandeja interna (FASE 23), exportación de reportes y comprobantes
imprimibles (FASE 24) y auditoría avanzada (FASE 25). Siguen sin fase asignada, a priorizar:

```text
Notificaciones por WhatsApp (el canal ya está previsto en NotificationChannel)
Frontend React 19 (consumo de esta API)
```

El orden de trabajo de abajo se usó para las FASES 10 a 13 y sigue siendo la referencia.

Pero la implementación debe comenzar primero por el **diseño funcional y de datos**, no directamente por el CRUD.

Orden recomendado:

```text
10.1 Definir catálogo de repuestos
      ↓
10.2 Definir categorías
      ↓
10.3 Definir proveedores
      ↓
10.4 Definir modelo de inventario
      ↓
10.5 Definir movimientos
      ↓
10.6 Definir relación WorkOrder ↔ repuestos
      ↓
10.7 Definir impacto económico
      ↓
10.8 Implementar migrations
      ↓
10.9 Models / Enums
      ↓
10.10 Services
      ↓
10.11 Requests
      ↓
10.12 Resources
      ↓
10.13 Controllers
      ↓
10.14 Permissions
      ↓
10.15 Exceptions
      ↓
10.16 Tests / regresión
```

La prioridad es **no crear un CRUD aislado de repuestos**, sino diseñar correctamente el vínculo entre:

```text
Catálogo
    +
Inventario
    +
WorkOrder
    +
Costos
    +
Precio de venta
```

para que el sistema pueda crecer posteriormente hacia compras, facturación y rentabilidad.

---

# 12. Estado final del handoff

## Construido

```text
[✓] Autenticación
[✓] Usuarios
[✓] Roles
[✓] Permisos
[✓] Clientes
[✓] Vehículos
[✓] Modelos de vehículos
[✓] Recepciones
[✓] Categorías de servicio
[✓] Checklist
[✓] Diagnóstico
[✓] Mecánicos
[✓] Presupuestos
[✓] Items de presupuesto
[✓] Estados de presupuesto
[✓] Orden de trabajo
[✓] Items de orden de trabajo
[✓] Estados de WorkOrder
[✓] Transiciones
[✓] API Resources
[✓] API Response
[✓] Exceptions
[✓] Error Codes
[✓] HTTP status standardization
[✓] Auditoría de Controllers
[✓] FASE 9.14
[✓] Catálogo de repuestos (FASE 10)
[✓] Categorías de repuestos (FASE 10)
[✓] Proveedores (FASE 10)
[✓] Inventario (FASE 11)
[✓] Movimientos de inventario (FASE 11)
[✓] Repuestos utilizados en WorkOrder (FASE 12)
[✓] Catálogo de servicios (FASE 13)
[✓] Asignación de mecánicos y comisiones (FASE 13)
[✓] Tiempo estimado, horas reales y costo del mecánico — opcionales (FASE 13)
[✓] Cierre económico, margen y liquidación de comisiones (FASE 14)
[✓] Facturación con timbrado e IVA incluido (FASE 15)
[✓] Pagos, recibos y resumen de caja (FASE 16)
[✓] Entrega del vehículo con comprobante y conformidad (FASE 17)
[✓] Roles del taller: recepcionista, jefe-taller, cajero, depósito
[✓] Historial del vehículo con resumen, visitas, repuestos y kilometraje (FASE 18)
[✓] Mantenimiento preventivo con planes, km estimado y alertas (FASE 19)
[✓] Reportes y dashboard por área (FASE 20)
[✓] Compras con IVA incluido e ingreso de stock (FASE 21)
[✓] Pagos a proveedores y cuentas por pagar (FASE 22)
[✓] Notificaciones por email y bandeja interna (FASE 23)
[✓] Exportación de reportes (CSV/PDF) y comprobantes imprimibles (FASE 24)
[✓] Auditoría avanzada de precios, facturación, seguridad y datos maestros (FASE 25)
[✓] Preferencias visuales por usuario y logo del sistema (2.50)
[✓] Catálogo de ítems de diagnóstico (2.53)
[✓] Recordatorios de factura al destinatario correcto (2.54)
[✓] Códigos internos con bloqueo (2.55)
[✓] Importes del presupuesto en decimal(14,2) (2.56)
[✓] Registro de la respuesta del cliente al presupuesto (2.57)
[✓] Spike T0 de tenancy, descartado; informe en docs/spike-t0-informe.md (2.58)
[✓] Cliente persona o empresa (2.59)
[✓] Aseguradoras y sus contactos (2.60)
[✓] Siniestros (2.61)
[✓] Documentos privados de siniestros (2.62)
[✓] Responsable por línea del presupuesto (2.63)
[✓] Autorizaciones de la aseguradora (2.64)
```

## Próximo

```text
[ ] Notificaciones por WhatsApp
[ ] Frontend React 19
```

---

# 13. Regla de oro del proyecto

> **No diseñar cada módulo como una funcionalidad aislada.**

Cada nueva entidad debe analizarse considerando:

```text
¿Quién la crea?
¿Quién la modifica?
¿Quién la utiliza?
¿En qué estado puede estar?
¿Qué entidades dependen de ella?
¿Qué impacto económico tiene?
¿Qué pasa si se cancela?
¿Qué pasa si se elimina?
¿Qué permisos requiere?
¿Qué error_code necesita?
¿Cómo afecta al flujo de la WorkOrder?
¿Cómo afectará a facturación e inventario?
```

El objetivo final no es simplemente tener muchos CRUDs.

El objetivo es construir un **sistema coherente de gestión de taller**, donde:

```text
Recepción
    ↓
Diagnóstico
    ↓
Presupuesto
    ↓
Aprobación
    ↓
Orden de trabajo
    ↓
Ejecución
    ↓
Repuestos + Mano de obra
    ↓
Cierre
    ↓
Facturación
    ↓
Pago
    ↓
Entrega
    ↓
Historial
```

mantenga integridad de datos, reglas de negocio claras y una API consistente durante toda la evolución del sistema.

---

# 14. Discrepancias conocidas y deuda técnica (verificado el 2026-10-17; reverificado el 2026-10-19 y el 2026-10-28)

Registradas durante la revisión documental de la v1.2; cada una se corrigió después con una decisión del usuario
(2026-10-19). La verificación del frontend se quitó de la lista: no está en este repositorio y no hace falta
comprobarlo desde aquí. Cada deuda nueva necesita una decisión antes de tocar el código.

Las deudas #14 a #16 y las discrepancias D1–D10 (14.1) se registraron el 2026-10-28 en la entrega E1 de la
especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §9). Las tres deudas son decisiones ya
aprobadas (E y P8, §10.2 de la especificación), que solo faltaba registrar.

| # | Tema | Evidencia | Efecto |
|---|---|---|---|
| 1 | ~~Validación de "recepción abierta" con estados inexistentes~~ **Resuelto el 2026-10-19** | Las Requests buscaban `pending, diagnosis, approved, in_progress, waiting_parts` (strings; tres no existen en `ReceptionStatus`, faltaban `repair` y `completed`) | Ahora usan `ReceptionStatus::openStatuses()` (todo salvo `delivered`/`cancelled`). Al editar solo se valida si la recepción editada está abierta. Además, anular una entrega que reabriría la recepción cuando el vehículo ya tiene otra abierta → `DELIVERY_VEHICLE_HAS_OPEN_RECEPTION` (409) |
| 2 | ~~Estados de recepción sin transiciones~~ **Resuelto el 2026-10-19** | Ningún endpoint asignaba `in_progress`, `repair`, `completed` ni `cancelled` | Ahora el estado se deriva de presupuestos y OT (`ReceptionStatusService::sync()`) y existe la cancelación manual (`POST receptions/{reception}/cancel`). Ver 2.7 |
| 3 | ~~Estados de OT sin uso~~ **Resuelto el 2026-10-19** | `WorkOrderStatus::WAITING_PARTS` y `::DELIVERED` no se asignaban en ningún Service | Eliminados del enum y de las listas de "OT abierta" de la entrega y los reportes. La columna es `string` y nunca guardó esos valores: sin migración. Ver 2.15 |
| 4 | ~~Errores sin `error_code`~~ **Resuelto el 2026-10-19** | `ReceptionController` (categorías de recepción procesada, eliminar recepción procesada), `ReceptionCheckListController` (checklist de recepción procesada) y `DiagnosticItemPhotoController` (actualizar sin datos) llamaban a `errorResponse(mensaje, 422)` sin código | Ahora responden `RECEPTION_CATEGORIES_NOT_EDITABLE`, `RECEPTION_NOT_DELETABLE`, `RECEPTION_CHECKLIST_NOT_EDITABLE` y `DIAGNOSTIC_ITEM_PHOTO_NOTHING_TO_UPDATE`; mismo `422` y mismo mensaje |
| 5 | ~~IVA del presupuesto con `float`~~ **Resuelto el 2026-10-19** | `BudgetService::recalculateBudget()`: `$tax = $subtotal * $taxRate` con `config('budget.tax_rate') = 0.10`; el total del ítem (`BudgetItemController`, `BudgetItemSeeder`) también era `cantidad * precio` en `float`. Dos fuentes de IVA: `config/budget.php` (0.10) y `config/invoice.php` (`default_tax_rate = 10`) | Ahora todo con BCMath y half-up a 2 decimales (`BudgetService::itemTotal()`); el IVA del presupuesto usa `invoice.default_tax_rate` y se eliminó `config/budget.php`. Los presupuestos existentes no se recalculan (la diferencia posible era de un centavo y los aprobados no deben cambiar) |
| 6 | ~~Middleware sin registrar~~ **Resuelto el 2026-10-19** | `App\Http\Middleware\EnsureJsonResponse` no aparecía en `bootstrap/app.php` ni en las rutas (y respondía `406 {"error": "JSON only"}`, fuera del contrato). Sin `Accept: application/json`: request sin token → `500` HTML (redirección a una ruta `login` inexistente), validación → `302`, ruta inexistente → `404` HTML | Ahora es middleware global: fija `Accept: application/json` en `api/*` y todo responde con el contrato (`401`, `422`, `404`, `405`…). Test: `tests/Feature/Http/JsonResponseTest` |
| 7 | ~~Seeders no idempotentes~~ **Resuelto el 2026-10-19** | `BrandSeeder` (`insert`), `VehicleModelSeeder`, `ClientsSeeder`, `VehiclesSeeder`, `ReceptionSeeder`, `DiagnosticItemSeeder`, `BudgetSeeder` y `BudgetItemSeeder` creaban sin verificar si el dato existía, y referenciaban marcas, clientes, vehículos, categorías y presupuestos por id fijo (`VehiclesSeeder` además combinaba marcas con modelos de otra marca, p. ej. Hyundai + Corolla Cross) | Ahora omiten lo existente por clave natural (nombre, documento, placa, código de categoría; recepción si el vehículo ya tiene, presupuesto si la recepción ya tiene, ítems si el presupuesto ya tiene) y resuelven las relaciones por esas claves. `BudgetSeeder` crea por `BudgetService::create()` (código y estado derivado de la recepción). `DatabaseSeederTest` corre todo dos veces y compara conteos |
| 8 | ~~Tests que dependen de la red~~ **Resuelto el 2026-10-19** | `Store/UpdateUserRequest` y `Store/UpdateClientRequest` usan `email:rfc,dns`; los de usuario además `Password::uncompromised()` (simulado con `Http::fake` en los tests) | Los tests necesitaban resolver DNS (`gmail.com`). Ahora la consulta DNS depende de `config('validation.email_dns')` (`EMAIL_DNS_CHECK`, `true` por defecto); `phpunit.xml` la desactiva y los tests corren sin red. `ClientTest` verifica ambos modos con un dominio `.invalid` |
| 10 | ~~Alta de mecánico con costo o comisión `null` → `500`~~ **Resuelto el 2026-10-21** | `StoreMechanicRequest` declaraba `hour_cost` y `commission_percentage` como `nullable`, pero las columnas son `NOT NULL DEFAULT 0` (migración `000001`). `POST /api/mechanics` con `"hour_cost": null` respondía `500 INTERNAL_SERVER_ERROR` | Decisión del usuario: rechazar con `422`. Alta y edición validan `sometimes\|required`: sin enviarlos quedan en `0`; vacíos → `422 VALIDATION_ERROR`. Test: `MechanicTest::test_cost_and_commission_cannot_be_null` |
| 11 | ~~Repuestos extra sin aprobación del cliente~~ **Resuelto el 2026-10-23 (opción 3, ver 2.49)** | `POST work-orders/{id}/parts` agrega repuestos fuera del presupuesto aprobado (`work_order_item_id` `null`, `is_budgeted` `false`, precio de venta del repuesto o el informado) con la OT `pending\|in_progress\|paused`. El cierre (6.9) y la factura (6.10) suman lo realmente usado, extras incluidos | El cliente paga más que el presupuesto que aprobó, sin registro de su conformidad ni aviso. La diferencia solo se ve en el cierre (`variance`, con `work-orders.view-economics`). Hoy se puede formalizar con un presupuesto complementario en la misma recepción y otra OT. Opciones a decidir con los clientes del taller: (1) mantener y mostrar en OT y factura lo no presupuestado y la diferencia; (2) prohibir extras y usar siempre el presupuesto complementario; (3) extras con aprobación registrada del cliente antes de consumir o facturar (diseño nuevo, con migración). **Se eligió la 3:** el extra nace `awaiting_approval` y se registra la respuesta del cliente (medio, contacto, quién la cargó) antes de reservar, consumir o completar la OT |
| 12 | ~~Requests ocasionales sin el `.env` (entorno local)~~ **Resuelto el 2026-10-22** | En la revisión visual de F9, un request concurrente del frontend falló y el navegador lo informó como CORS; `laravel.log`: `production.ERROR: Database connection [framework_base] not configured` (sin `APP_ENV` ni `DB_CONNECTION`: corrió sin las variables del `.env`). Reproducido: 13 de 300 requests concurrentes con 500 | Causa: Apache multihilo de Laragon (`mpm_winnt` + PHP como módulo ZTS), `putenv` no es seguro entre hilos. Laragon pasó a **Nginx** (puerto 80, `php-cgi` con procesos hijos, `client_max_body_size 2000M`): 900 de 900 requests concurrentes en 200, sin errores nuevos. Además `database.default` pasó de `framework_base` (resto del scaffold) a `mysql`; el 2026-10-24 la conexión del proyecto, que se llamaba `synctaller` y no se usaba (Laravel completaba `mysql` con su configuración interna), se renombró a `mysql`. No usar `config:cache` en desarrollo: con la config cacheada `php artisan test` usaría MySQL y `RefreshDatabase` borraría la base |
| 13 | **Abierta (2026-10-24), en diseño desde el 2026-10-27: clientes aseguradoras.** Especificación de trabajo en `docs/especificacion-aseguradoras.html` (versión 3): decisiones A–G y P1–P9 aprobadas; primeras respuestas externas a G1–G5 (2026-10-28); correcciones previas E2–E5 implementadas (2.54–2.57). El módulo (M1–M12) no está implementado | No hay concepto de aseguradora, siniestro, póliza ni deducible. La factura toma el `client_id` del dueño de la recepción (`InvoiceService::generate`); en borrador se pueden cambiar `customer_name`, `customer_tax_id`, `customer_address` y la condición (`UpdateInvoiceRequest`), así que hoy se puede facturar a nombre de la aseguradora y a crédito. Una sola factura vigente por OT (`WORK_ORDER_ALREADY_INVOICED`); el presupuesto lo aprueba el cliente, sin aprobación parcial por ítem | Reportes de ventas, clientes y cobros muestran al dueño, no a la aseguradora; no se puede dividir la factura (deducible al dueño y el resto a la aseguradora); no se registra el número de siniestro ni el perito. Preguntas abiertas: ¿quién paga el deducible y con qué factura?, ¿quién aprueba el presupuesto (perito, dueño o ambos) y si puede ser parcial?, ¿siniestro o póliza en presupuesto y factura?, ¿cuentas por cobrar por aseguradora? Para entregar con pago diferido hay que facturar a crédito (la entrega exige la factura de contado pagada) |
| 9 | ~~Rama remota antigua~~ **Resuelto el 2026-10-17** | `origin/feature/diagnostic-item-seeders-controllers-migrations-models` (commit `795d830519b28d1abc22ba7fee92da04e50af4a5`, 2026-08-12) sin mergear | Eliminada a pedido del usuario: sus archivos existían en `main` en versiones posteriores. Ese día también se eliminaron todas las ramas ya integradas en `main` (locales y remotas); su contenido sigue en el historial de `main` |
| 14 | **Abierta (2026-10-28): disco `local` con `serve` activo y sin uso** | `config/filesystems.php`: el disco `local` (`storage/app/private`) tiene `'serve' => true`. Laravel registra `GET` y `PUT storage/{path}` con URL firmada: la firma usa la `APP_KEY` y no conoce permisos, taller ni auditoría. Ninguna parte de `app/` usa ese disco | Hoy no expone nada (la carpeta solo tiene `.gitignore`), pero cualquier archivo que se guarde ahí quedaría descargable por URL firmada. Decisión aprobada (E): no se usa para el módulo, que tendrá su propio disco privado sin `serve`; su revisión queda como deuda, **sin desactivarlo en esta etapa** |
| 15 | **Abierta (2026-10-28): fotos y firmas con URL pública (deuda de seguridad, P8)** | Fotos de recepción (`ReceptionPhotoController`), fotos de ítems de diagnóstico (`DiagnosticItemPhotoController`) y firmas de entrega (`VehicleDeliveryService`) en el disco `public`, con URL permanente y nombre aleatorio. El frontend las muestra con `<img src>`, que no envía el token | Una URL filtrada no se puede revocar hasta mover el archivo; no hay permiso ni auditoría de acceso. Decisión aprobada (P8): tarea independiente **después de T4** (storage por taller), primero la firma y después las fotos, coordinada con el frontend (descarga con token). **Requisito antes del primer taller comercial.** Hasta entonces no se agregan tipos de archivo nuevos al disco `public`, salvo branding |
| 16 | **Abierta (2026-10-28): sin análisis antivirus de archivos subidos (riesgo técnico)** | Las subidas solo validan tipo, extensión y tamaño (fotos, firma, logo, favicon; y los documentos de siniestros previstos en M4) | La validación no sustituye un antivirus. Decisión aprobada (E): queda fuera del alcance actual y se documenta como riesgo pendiente; decidir antes de recibir documentos de terceros en producción |

## 14.1 Discrepancias del estudio de aseguradoras (D1–D10, 2026-10-28)

D1–D6 salen del estudio previo «Aseguradoras y siniestros en SyncTaller» (§0) y D7–D10 de
`docs/especificacion-aseguradoras.html` (§2.4). Se reverificaron el 2026-10-28 contra `main`. Se registran sin
corregir: las que siguen abiertas esperan decisión del usuario.

| # | Discrepancia | Evidencia | Estado |
|---|---|---|---|
| D1 | Un pedido mencionaba Laravel 12; el código usa Laravel 13.38 | `composer.json`, `php artisan --version`, handoff 2.51 | **Sin acción**: no es un error del repositorio. `CLAUDE.md` §1 ya dice Laravel 13.38 |
| D2 | `CLAUDE.md` §7 decía "Sin deudas pendientes" con la #13 abierta | `CLAUDE.md` §7; tabla de arriba, #13 | **Resuelta el 2026-10-28** (E1): §7 lista las deudas abiertas (#13–#16) |
| D3 | `receptions.approved_by` existe en la tabla, el modelo, `ShowReceptionResource` y la búsqueda de `ReceptionController`, pero ningún Service ni Request la asigna: siempre es `null` | Migración `000009:28`; `Reception.php:32,59`; `ShowReceptionResource.php:46`; `ReceptionController.php:43,91` | **A decidir**: eliminarla (migración y contrato) o darle uso. El estudio la dejaba para la Fase 0 |
| D4 | El handoff (§11, "Siguen sin fase asignada", y §12, "Próximo") lista "Frontend React 19" como pendiente; existe en `../synctaller-frontend` (`CLAUDE.md` §1) | `docs/handoff.md`, §11 y §12 | **A decidir**: actualizar esas dos listas (solo documentación) |
| D5 | `composer.lock` modificado sin commitear en `main` | `git status` del 2026-10-27 | **Resuelta el 2026-10-27** (E0, P1): se descartaron los cambios locales; `vendor/` alineado el 2026-10-28 |
| D6 | La cabecera del handoff tiene fecha 2026-10-17 con contenido posterior, y en esta tabla la fila #9 está después de la #13 | `docs/handoff.md:6`; tabla de arriba | **A decidir**: actualizar la fecha y reordenar (solo documentación) |
| D7 | `CLAUDE.md` 6.1 dice que el email del cliente es único, pero la unicidad existe solo en la validación: `clients.email` no tiene índice único | Migración `000005:20`; `StoreClientRequest` | **A decidir**: índice único en la base (con los datos existentes revisados) o corregir la regla en `CLAUDE.md` |
| D8 | `document_number` y `email` del cliente son opcionales en la base pero obligatorios en la API | Migración `000005:16,20`; `StoreClientRequest` | **A decidir**. Relacionado con M1 (cliente empresa), que redefine qué es obligatorio por tipo |
| D9 | "Datos maestros en uso no se eliminan" (6.1) no incluye a los clientes: `ClientController::destroy` hace la baja con sufijo sin verificar si el cliente tiene vehículos, recepciones o facturas, y la lógica está en el controller, no en un Service | `ClientController.php:127-142` | **Resuelta el 2026-10-29** (M1, 2.59): la baja pasó a `ClientService::delete` y responde `409 CLIENT_IN_USE` con vehículos vigentes, recepciones o facturas. El bloqueo para el cliente de una aseguradora (`CLIENT_LINKED_TO_INSURER`) llega con M2 |
| D10 | Un pedido citaba dos informes `.md` (`Aseguradoras y siniestros en SyncTaller.md`, `Decisiones del módulo de aseguradoras.md`) que no están en el repositorio | Búsqueda en el repositorio | **Sin acción**: el contenido está en las páginas publicadas (ver `docs/continuidad-cambio-notebook.md`, sección 6) |

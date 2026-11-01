<!-- Handoff original: FASES 11 a 25 (secciones 2.24 a 2.38). Líneas 417–1116 de docs/handoff.md hasta el commit 9cae759. Mapa de secciones: docs/handoff.md. -->

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


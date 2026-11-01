<!-- Handoff original: diseño y roadmap original (secciones 5 y 6). Líneas 3147–3873 de docs/handoff.md hasta el commit 9cae759. Mapa de secciones: docs/handoff.md. -->

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


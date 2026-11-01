<!-- Handoff original: memoria técnica (sección 7). Líneas 3874–4165 de docs/handoff.md hasta el commit 9cae759. Mapa de secciones: docs/handoff.md. -->

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


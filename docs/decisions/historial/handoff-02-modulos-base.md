<!-- Handoff original: módulos base (secciones 2.1 a 2.23). Líneas 368–416, 1946–2685 de docs/handoff.md hasta el commit 9cae759. Mapa de secciones: docs/handoff.md. -->

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


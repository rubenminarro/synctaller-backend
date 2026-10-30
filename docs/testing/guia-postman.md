# Guía de pruebas con Postman — SyncTaller

**Alcance:** API al cierre de la FASE 20 (reportes y dashboard), con la FASE 19 (mantenimiento preventivo), la FASE 18 (historial del vehículo), los roles del taller, la FASE 17 (entrega del vehículo), la FASE 16 (pagos, vuelto, recibos y resumen de caja), la FASE 15 (facturación con timbrado e IVA incluido), la FASE 14 (cierre económico de la OT y liquidación de comisiones), incluidos los ajustes posteriores a la FASE 12 (rutas de vehículos por cliente, permisos de ítems de presupuesto, `error_code` del login y validaciones de texto) y la corrección de `roles/permissions-grouped-by-module`.
**Uso:** ejecutar los requests en el orden indicado; cada sección parte de los datos creados en las anteriores.

---

## 0. Configuración de Postman

### 0.1 Environment

| Variable | Valor inicial |
|---|---|
| `base_url` | `http://localhost:8000/api` |
| `admin_email` | `admin@mail.com.py` |
| `mechanic_email` | `jperez@mail.com.py` |
| `password` | valor de `ADMIN_PASSWORD` en `.env` |
| `token` | (vacío; se completa con el token del login) |
| `token_admin` | (vacío) |
| `token_mec` | (vacío) |
| `token_caja` | (vacío; B.6) |

Variables de IDs que se irán completando con las respuestas: `client_id`, `client2_id`, `brand_id`, `model_id`, `vehicle_id`, `vehicle2_id`, `reception_id`, `budget_id`, `budget_item_id`, `mechanic_id`, `work_order_id`, `work_order_item_id`, `category_id`, `supplier_id`, `part_id`, `part_unit_id`, `part_status_id`, `movement_id`, `budget2_id`, `work_order2_id`, `work_order3_id`, `work_order4_id`, `line_id`, `extra_line_id`, `pending_line_id`, `mec1_id`, `mec2_id`, `service_category_id`, `service_id`, `service_unused_id`, `budget5_id`, `work_order5_id`, `labor_item_id`, `service_item_id`, `work_order6_id`, `work_order7_id`, `stamp_id`, `invoice_id`, `invoice_service_item_id`, `invoice_labor_item_id`, `invoice2_id`, `invoice3_id`, `invoice4_id`, `payment1_id`, `payment2_id`, `payment3_id`, `payment4_id`, `reception2_id`, `budget8_id`, `work_order8_id`, `work_order8_item_id`, `invoice5_id`, `delivery_id`, `delivery2_id`, `maint_plan_id`, `brakes_plan_id`, `reception3_id`, `budget9_id`, `work_order9_id`.

### 0.2 Headers comunes

Configurar a nivel **colección**:

| Header | Valor |
|---|---|
| `Accept` | `application/json` |
| `Content-Type` | `application/json` |
| `Authorization` | `Bearer {{token}}` |

> El login es el único request **sin** `Authorization`.
>
> Sin `Accept` la API también responde JSON (por ejemplo, `GET {{base_url}}/receptions` sin header ni token →
> `401 UNAUTHENTICATED` en JSON, no HTML).
> Para actuar como mecánico, asignar `token = {{token_mec}}`; para volver, `token = {{token_admin}}`.

### 0.3 Formato de respuesta a verificar

Éxito:

```json
{ "success": true, "status": 200, "message": "...", "data": {}, "errors": null, "meta": null, "timestamp": "..." }
```

Error:

```json
{ "success": false, "status": 422, "message": "...", "data": null, "errors": null, "error_code": "..." }
```

En todos los casos: `status` del cuerpo = código HTTP; nunca aparecen `exception`, `file`, `line` ni `trace`.

Códigos internos (`BUD-`, `OT-`, `COM-`, `REP-`, `SRV-`, `DIA-`): salen de una serie con bloqueo y conservan su
formato. Puede haber huecos (un alta fallida no consume el número, pero no se garantiza continuidad). Si un código
llegara a repetirse (cargado por fuera de la serie), el alta responde `409 DOCUMENT_CODE_CONFLICT` en lugar de 500; no
se puede provocar desde la API. La concurrencia se prueba con `php tests/Manual/document_sequence_concurrency.php`
contra MySQL (ver `docs/handoff.md`, 2.55).

### 0.4 Datos precargados por el seeder

| Dato | Valor |
|---|---|
| Mecánicos | `MEC-001`, `MEC-002` |
| Categorías de servicio | IDs `1`, `2`, … |
| Repuestos | `FIL-ACE-001`, `FIL-AIR-001`, `FRE-PAS-001`, `LUB-5W30-001`, `REF-REF-001` |
| Stock inicial | 12, 8, 4, 60 y 3.5 (`REF-REF-001` bajo mínimo) |

---

## FASE BASE — Autenticación y permisos

### B.1 Login administrador

| | |
|---|---|
| **Método** | `POST` |
| **Endpoint** | `{{base_url}}/login` |
| **Headers** | `Accept: application/json`, `Content-Type: application/json` |

Body éxito:

```json
{
  "email": "{{admin_email}}",
  "password": "{{password}}"
}
```

Esperado: `200`, `data.token`, `data.user.roles` incluye `administrador`. Copiar `data.token` en `token_admin` y en `token`.

Casos de error:

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Contraseña incorrecta | `{ "email": "{{admin_email}}", "password": "incorrecta" }` | 401 | `UNAUTHENTICATED` (`message: "Credenciales inválidas."`, `errors.email: ["Email incorrecto."]`) |
| Email mal formado | `{ "email": "admin", "password": "x" }` | 422 | `VALIDATION_ERROR` |
| Body vacío | `{}` | 422 | `VALIDATION_ERROR` |

### B.2 Login mecánico

| | |
|---|---|
| **Método** | `POST` |
| **Endpoint** | `{{base_url}}/login` |

```json
{
  "email": "{{mechanic_email}}",
  "password": "{{password}}"
}
```

Esperado: `200`. Copiar `data.token` en `token_mec`.

### B.2b Usuario autenticado

| | |
|---|---|
| **Método** | `GET` |
| **Endpoint** | `{{base_url}}/me` |
| **Body** | ninguno |

| Caso | Token | HTTP | Esperado |
|---|---|---|---|
| Administrador | `{{token_admin}}` | 200 | `data.user` con rol `administrador`; `data.permissions` con **todos** los permisos (incluye `users.index`, `audit-logs.index`); `data.mechanic` `null` |
| Mecánico | `{{token_mec}}` | 200 | `data.user.roles[0].name = "mechanic"`; `data.permissions` solo con los del rol (no incluye `users.index`); `data.mechanic` con `id`, `employee_code` y `full_name` de su ficha (`null` si aún no tiene) |
| Sin token | — | 401 | `UNAUTHENTICATED` |

### B.3 Accesos sin autenticación

| Caso | Método / Endpoint | Headers | HTTP | error_code |
|---|---|---|---|---|
| Sin token | `GET {{base_url}}/parts` | sin `Authorization` | 401 | `UNAUTHENTICATED` |
| Token inválido | `GET {{base_url}}/parts` | `Authorization: Bearer xxx` | 401 | `UNAUTHENTICATED` |

### B.4 Logout

| | |
|---|---|
| **Método** | `POST` |
| **Endpoint** | `{{base_url}}/logout` |
| **Body** | ninguno |

Esperado: `200`. Repetir cualquier request con el mismo token → `401 UNAUTHENTICATED`. Después, volver a ejecutar B.1 y B.2.

### B.5 Permisos agrupados por módulo

`GET {{base_url}}/roles/permissions-grouped-by-module`

Esperado: `200`, `data` es una lista de `{ "module": "...", "list": [ { "id", "name" } ] }` (incluye los módulos `roles` y `work-orders`).

| Caso | Token | HTTP | error_code |
|---|---|---|---|
| Administrador | `{{token_admin}}` | 200 | — (antes de la corrección devolvía `404 RESOURCE_NOT_FOUND`) |
| Mecánico | `{{token_mec}}` | 403 | `FORBIDDEN` |

### B.6 Roles del taller

`GET {{base_url}}/roles?per_page=20` (token admin) → `200` con 6 roles: `administrador`, `mechanic`, `recepcionista`, `jefe-taller`, `cajero`, `deposito` (cada uno con `description`).

Protecciones (token admin):

| Caso | Request | HTTP | error_code |
|---|---|---|---|
| Eliminar el rol administrador | `DELETE {{base_url}}/roles/{id de administrador}` | 422 | `ROLE_PROTECTED` |
| Renombrar el rol administrador | `PATCH {{base_url}}/roles/{id de administrador}` con `{ "name": "superusuario", "description": "Administra todo el sistema", "guard_name": "api" }` | 422 | `ROLE_PROTECTED` |
| Eliminar un rol con usuarios | `DELETE {{base_url}}/roles/{id de mechanic}` | 409 | `ROLE_IN_USE` |
| Eliminarse a uno mismo | `DELETE {{base_url}}/users/{id del admin autenticado}` | 422 | `USER_SELF_ACTION` |
| Cambiarse el propio rol | `PATCH {{base_url}}/users/{id del admin autenticado}` con `{ "role": "cajero" }` | 422 | `USER_SELF_ACTION` |

Para probar un rol se crea un usuario con ese rol. Ejemplo con cajero:

`POST {{base_url}}/users`

```json
{
  "name": "qa_cajero",
  "email": "qa.cajero@gmail.com",
  "password": "Cajero#2026Qa",
  "password_confirmation": "Cajero#2026Qa",
  "role": "cajero",
  "first_name": "Carla",
  "last_name": "Caja"
}
```

Esperado: `201`. Login con ese email y contraseña (B.1) → guardar el token en `token_caja`.

| Caso (token = `{{token_caja}}`) | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Ver facturas | `GET {{base_url}}/invoices` | 200 | — |
| Resumen de caja | `GET {{base_url}}/payments/summary` | 200 | — |
| Ver recepciones | `GET {{base_url}}/receptions` | 403 | `FORBIDDEN` |
| Crear timbrado | `POST {{base_url}}/invoice-stamps` | 403 | `FORBIDDEN` |

> Mismo procedimiento para `recepcionista` (clientes, recepciones, presupuestos, entregas), `jefe-taller` (OT, mecánicos, diagnóstico) y `deposito` (repuestos, proveedores, inventario). Qué puede hacer cada rol: sección 5 del `CLAUDE.md` (detalle histórico en `docs/handoff.md`, sección 2.3).

---

## FASE BASE — Datos maestros

Token: administrador.

### M.1 Crear cliente

`POST {{base_url}}/clients`

```json
{
  "document_number": "QA-1001",
  "first_name": "Lucia",
  "last_name": "Benitez",
  "email": "qa.lucia.benitez@gmail.com",
  "phone": "+595981000111"
}
```

Esperado: `201`. Guardar `data.id` → `client_id`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Documento repetido | mismo body | 422 | `VALIDATION_ERROR` (`errors.document_number`) |
| Teléfono inválido | `{ ..., "document_number": "QA-1003", "email": "qa3@gmail.com", "phone": "abc" }` | 422 | `VALIDATION_ERROR` (`errors.phone`) |
| Email sin dominio real | `{ ..., "email": "qa@dominio-inexistente.zz" }` | 422 | `VALIDATION_ERROR` (regla `email:dns`) |

Segundo cliente (para las pruebas de vehículos por cliente):

```json
{
  "document_number": "QA-1002",
  "first_name": "Pedro",
  "last_name": "Rojas",
  "email": "qa.pedro.rojas@gmail.com",
  "phone": "+595981000222"
}
```

Esperado: `201` → `client2_id`.

### M.2 Crear marca

`POST {{base_url}}/brands`

```json
{ "name": "Marca QA" }
```

Esperado: `201` → `brand_id`.

### M.3 Crear modelo

`POST {{base_url}}/vehicle-models`

```json
{ "name": "Modelo QA 1", "brand_id": {{brand_id}} }
```

Esperado: `201` → `model_id`.

### M.4 Crear vehículo

`POST {{base_url}}/vehicles`

```json
{
  "client_id": {{client_id}},
  "brand_id": {{brand_id}},
  "vehicle_model_id": {{model_id}},
  "plate": "QAA123",
  "no_plate": false,
  "year": 2020,
  "color": "white",
  "fuel_type": "gasoline",
  "transmission": "manual",
  "mileage": 45000
}
```

Esperado: `201` → `vehicle_id`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Sin chapa con `no_plate: false` | quitar `plate` | 422 | `VALIDATION_ERROR` (`errors.plate`) |
| Color fuera del enum | `"color": "fucsia"` | 422 | `VALIDATION_ERROR` (`errors.color`) |
| Chapa repetida | mismo body | 422 | `VALIDATION_ERROR` (`errors.plate`) |

**M.4b Ajustes de datos maestros (2026-10-21)**

| Caso | Request | HTTP | Esperado |
|---|---|---|---|
| Modelos de una marca | `GET {{base_url}}/vehicle-models?brand_id={{brand_id}}` | 200 | Solo modelos con `brand.id = {{brand_id}}` |
| Eliminar marca con vehículos | `DELETE {{base_url}}/brands/{{brand_id}}` | 409 | `BRAND_HAS_VEHICLES` |
| Eliminar modelo con vehículos | `DELETE {{base_url}}/vehicle-models/{{model_id}}` | 409 | `VEHICLE_MODEL_HAS_VEHICLES` |
| Eliminar marca con modelos (sin vehículos) | crear marca y modelo nuevos; `DELETE {{base_url}}/brands/{id}` | 409 | `BRAND_HAS_MODELS` |
| Mecánico con costo vacío | `POST {{base_url}}/mechanics` con `"hour_cost": null` | 422 | `VALIDATION_ERROR` (`errors.hour_cost`) |
| RUC/CI en el listado | `GET {{base_url}}/clients` | 200 | Cada cliente trae `document_number` |
| Vaciar opcionales del vehículo | `PATCH {{base_url}}/vehicles/{{vehicle_id}}` con el body de M.4 y `"notes": null, "year": null` | 200 | `data.notes` y `data.year` en `null` |
| Vaciar notas del cliente | `PATCH {{base_url}}/clients/{{client_id}}` con `{ "notes": null }` | 200 | `data.notes = null` |

Cliente persona o empresa (M1, ver `docs/handoff.md`, 2.59). Todos los clientes traen `kind` (`value`/`label`),
`business_name`, `display_name` y `address`. Body de una empresa:

```json
{ "kind": "company", "document_number": "80012345-6", "business_name": "Seguros del Sur S.A.",
  "email": "facturacion@segurosdelsur.com.py", "phone": "+595211234567", "address": "Av. Mcal. López 1234, Asunción" }
```

| Caso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Persona sin `kind` (body de siempre) | `POST {{base_url}}/clients` | 201 | `kind.value` `person`, `display_name` "Nombre Apellido" |
| Empresa | `POST {{base_url}}/clients` con el body de arriba | 201 | `first_name`/`last_name` `null`, `display_name` = razón social |
| Empresa sin razón social / con nombre | ídem, sin `business_name` o con `first_name` | 422 | `VALIDATION_ERROR` en `business_name` / `first_name` |
| Persona con razón social | `POST {{base_url}}/clients` (persona) con `business_name` | 422 | `VALIDATION_ERROR` en `business_name` |
| Pasar una persona sin recepciones a empresa | `PATCH {{base_url}}/clients/{id}` con `{ "kind": "company", "business_name": "..." }` | 200 | Nombre y apellido quedan en `null` |
| Cambiar el tipo con recepciones o facturas | ídem, sobre un cliente con recepciones | 422 | `CLIENT_KIND_NOT_EDITABLE` |
| Solo empresas (o personas) | `GET {{base_url}}/clients?kind=company` (`person`; otro valor → 422) | 200 | Solo clientes de ese tipo |
| Buscar por razón social | `GET {{base_url}}/clients?search=Seguros` (también `?first_name=Seguros`, `vehicles?client_first_name=Seguros` y el `search` de recepciones, presupuestos, OT y diagnósticos) | 200 | Encuentra la empresa |
| Detalle de recepción o vehículo de una empresa | `GET {{base_url}}/receptions/{id}` | 200 | `client.full_name` = razón social |
| Eliminar un cliente con vehículos, recepciones o facturas | `DELETE {{base_url}}/clients/{{client_id}}` | 409 | `CLIENT_IN_USE` (antes se eliminaba) |
| Eliminar un cliente sin uso | `DELETE {{base_url}}/clients/{id}` | 200 | Baja lógica con sufijo en documento y email |

Aseguradoras (M2, ver `CLAUDE.md` 6.22 y `docs/handoff.md` 2.60). Alta con su cliente empresa nuevo:

```json
{ "client": { "document_number": "80012345-6", "business_name": "Seguros del Sur S.A.",
  "email": "siniestros@segurosdelsur.com.py", "phone": "+595211234567" }, "payment_term_days": 30 }
```

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Alta con cliente nuevo | `POST {{base_url}}/insurers` | el de arriba | 201 | `code` `ASE-…`, `client.kind` `company`, `client.notifications_enabled` `false`, `meta` `null`. Guardar `{{insurer_id}}` |
| Alta sobre un cliente empresa existente | ídem | `{ "client_id": {{company_client_id}} }` | 201 | El cliente conserva sus avisos; `meta.warnings` lo advierte |
| Sin cliente / con los dos | ídem | `{}` o `client_id` + `client` | 422 | `VALIDATION_ERROR` en `client_id` / `client` |
| Datos del cliente inválidos | ídem | `client` sin `business_name` | 422 | `VALIDATION_ERROR` en `client.business_name` |
| Cliente persona | ídem | `{ "client_id": {{client_id}} }` | 422 | `INSURER_CLIENT_NOT_COMPANY` |
| Cliente ya vinculado | ídem, otra vez con el mismo `client_id` | — | 409 | `INSURER_CLIENT_ALREADY_LINKED` |
| Listar / buscar / filtrar | `GET {{base_url}}/insurers?search=Sur&status=active` | — | 200 | `meta.pagination`, `contacts_count` |
| Editar plazo y notas | `PATCH {{base_url}}/insurers/{{insurer_id}}` | `{ "payment_term_days": 60 }` | 200 | — |
| Desactivar / activar | `POST {{base_url}}/insurers/{{insurer_id}}/deactivate` (y `activate`) | — | 200 | Repetir → 422 `INSURER_INVALID_STATUS_TRANSITION` |
| Agregar contacto principal | `POST {{base_url}}/insurers/{{insurer_id}}/contacts` | `{ "name": "Laura Benítez", "role": "claims", "is_primary": true }` | 201 | Marcar otro como principal desmarca este |
| Editar / eliminar contacto | `PATCH` / `DELETE {{base_url}}/insurers/{{insurer_id}}/contacts/{id}` | — | 200 | Contacto de otra aseguradora → 404 |
| Eliminar el cliente de una aseguradora | `DELETE {{base_url}}/clients/{cliente de la aseguradora}` | — | 409 | `CLIENT_LINKED_TO_INSURER` |
| Eliminar la aseguradora | `DELETE {{base_url}}/insurers/{{insurer_id}}` | — | 200 | Baja lógica; el cliente se puede vincular a otra aseguradora |
| Sin permiso | cajero: `POST {{base_url}}/insurers`; mecánico o depósito: `GET {{base_url}}/insurers` | — | 403 | `FORBIDDEN` |

Siniestros (M3, ver `CLAUDE.md` 6.23 y `docs/handoff.md` 2.61). Alta sobre una recepción abierta:

```json
{ "reception_id": {{reception_id}}, "insurer_id": {{insurer_id}}, "claim_type": "own", "loss_date": "2026-10-25",
  "claim_number": "SDS-2026-0457", "policy_number": "POL-778812", "adjuster_name": "Ramón Giménez", "deductible_amount": 0 }
```

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Alta propia | `POST {{base_url}}/claims` | el de arriba | 201 | `code` `SIN-…`, `status` `open`, `insured` copiado del cliente. Guardar `{{claim_id}}` |
| Alta de tercero sin asegurado | ídem, con `"claim_type": "third_party"` | — | 422 | `VALIDATION_ERROR` en `insured_name` |
| Otro siniestro en la misma recepción | ídem | — | 409 | `RECEPTION_ALREADY_HAS_CLAIM` |
| Número repetido en la aseguradora | ídem, otra recepción, mismo `claim_number` | — | 409 | `CLAIM_NUMBER_DUPLICATE` |
| Aseguradora inactiva / recepción cerrada | ídem | — | 422 | `INSURER_INACTIVE` / `RECEPTION_CLOSED` |
| Detalle de la recepción | `GET {{base_url}}/receptions/{{reception_id}}` | — | 200 | `claim` (código, número, estado, aseguradora); con el token del mecánico no aparece |
| Siniestro de la recepción | `GET {{base_url}}/receptions/{{reception_id}}/claim` | — | 200 | 404 si no tiene |
| Editar deducible | `PATCH {{base_url}}/claims/{{claim_id}}` | `{ "deductible_amount": 500000 }` (o `null`: desconocido) | 200 | — |
| Cambiar aseguradora o tipo | ídem | `{ "insurer_id": 1 }` | 422 | `VALIDATION_ERROR` (se cancela y se registra otro) |
| Cancelar | `POST {{base_url}}/claims/{{claim_id}}/cancel` | `{ "reason": "La aseguradora rechazó la cobertura." }` | 200 | `cancelled`; después, editar → `CLAIM_NOT_EDITABLE` y cancelar otra vez → `CLAIM_NOT_CANCELLABLE` |
| Entregar el vehículo | `POST {{base_url}}/receptions/{{reception_id}}/delivery` | — | 201 | El siniestro pasa a `closed`; al anular la entrega, vuelve a `open` |
| Cancelar la recepción | `POST {{base_url}}/receptions/{id}/cancel` | `{ "reason": "..." }` | 200 | El siniestro pasa a `cancelled` con "Recepción cancelada: …" |
| Eliminar una aseguradora con siniestros | `DELETE {{base_url}}/insurers/{{insurer_id}}` | — | 409 | `INSURER_IN_USE` |
| Listar / filtrar | `GET {{base_url}}/claims?search=SDS&status=open&insurer_id={{insurer_id}}` | — | 200 | Con `plate` y la aseguradora |
| Sin permiso | cajero: `PATCH {{base_url}}/claims/{id}`; mecánico o depósito: `GET {{base_url}}/claims` | — | 403 | `FORBIDDEN` |

Documentos del siniestro (M4, ver `CLAUDE.md` 6.24). En Postman: Body → form-data, `file` de tipo File, `type` y `notes` de tipo Text.

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Subir la denuncia (PDF) | `POST {{base_url}}/claims/{{claim_id}}/documents` | `file` = un PDF, `type` = `loss_report` | 201 | `original_name`, `mime`, `size`, `sha256`; nunca `path`. Guardar `{{claim_document_id}}` |
| Subir un SVG, un HTML o un archivo de más de 10 MB | ídem | — | 422 | `VALIDATION_ERROR` en `file` |
| Extensión que no coincide (una imagen renombrada a `.pdf`) | ídem | — | 422 | `VALIDATION_ERROR` en `file`: "La extensión del archivo no coincide con su contenido." |
| Listar | `GET {{base_url}}/claims/{{claim_id}}/documents` | — | 200 | Sin `path` |
| Descargar | `GET {{base_url}}/claims/{{claim_id}}/documents/{{claim_document_id}}/download` | — | 200 | Archivo adjunto con `X-Content-Type-Options: nosniff`; `GET audit-logs?event=downloaded` lo muestra |
| Documento de otro siniestro | `GET {{base_url}}/claims/{otro}/documents/{{claim_document_id}}/download` | — | 404 | `RESOURCE_NOT_FOUND` |
| Subir con el siniestro cancelado | `POST {{base_url}}/claims/{id cancelado}/documents` | — | 422 | `CLAIM_NOT_EDITABLE` |
| Dar de baja (administrador) | `DELETE {{base_url}}/claims/{{claim_id}}/documents/{{claim_document_id}}` | `{ "reason": "Duplicado." }` | 200 | Después, descargar → 404. Con el token del recepcionista → 403 |
| Sin permiso | cajero: subir; mecánico o depósito: listar o descargar | — | 403 | `FORBIDDEN` |

Responsable por línea del presupuesto (M5, ver `CLAUDE.md` 6.4). Todas las líneas traen `responsibility` (`value`/`label`).

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Línea sin `responsibility` | `POST {{base_url}}/budgets/{{budget_id}}/items` | el de siempre | 201 | `responsibility.value` `owner`, aun con siniestro |
| Línea a la aseguradora sin siniestro abierto | ídem | `"responsibility": "insurer"` | 422 | `BUDGET_ITEM_INSURER_WITHOUT_CLAIM` |
| Línea a la aseguradora con siniestro abierto | ídem, en la recepción de `{{claim_id}}` | `"responsibility": "insurer"` | 201 | `insurer` / "Aseguradora" |
| Cambiarla con el presupuesto enviado | `PATCH {{base_url}}/budgets/{{budget_id}}/items/{id}` | `{ "responsibility": "owner" }` | 422 | `BUDGET_NOT_EDITABLE` |
| Cancelar el siniestro | `POST {{base_url}}/claims/{{claim_id}}/cancel` | `{ "reason": "..." }` | 200 | Las líneas `insurer` de los borradores vuelven a `owner`; las de los enviados no cambian |

Autorizaciones de la aseguradora (M6, ver `CLAUDE.md` 6.25). Sobre un presupuesto **enviado o aprobado** de la recepción
de `{{claim_id}}` con líneas `insurer`. Importes sin IVA salvo `"amounts_include_tax": true`.

`POST {{base_url}}/budgets/{{budget_id}}/insurer-authorizations`

```json
{
  "amounts_include_tax": true,
  "decided_at": "2026-10-29",
  "channel": "email",
  "authorization_number": "AUT-001",
  "adjuster_name": "Ramón Giménez",
  "items": [
    { "budget_item_id": {{budget_item_id}}, "decision": "partial", "authorized_total": 330000, "notes": "Tope del baremo." },
    { "budget_item_id": {{budget_item2_id}}, "decision": "authorized" }
  ]
}
```

Esperado: `201`, `version` 1, `status.value` `current`, `result.value` `partial`; la primera línea con
`authorized_total` `300000.00` (330.000 con IVA) → `insurer_authorization_id`.

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Presupuesto en borrador | `POST …/budgets/{borrador}/insurer-authorizations` | el de arriba | 422 | `INSURER_AUTHORIZATION_BUDGET_NOT_AUTHORIZABLE` |
| Sin siniestro abierto | ídem, en una recepción sin siniestro (o cancelado) | ídem | 422 | `BUDGET_WITHOUT_CLAIM` |
| Falta una línea `insurer` | ídem | `items` con una sola línea | 422 | `INSURER_AUTHORIZATION_ITEMS_INCOMPLETE` |
| Línea del dueño u otro presupuesto | ídem | `budget_item_id` ajeno | 422 | `INSURER_AUTHORIZATION_ITEM_MISMATCH` |
| Más que lo presupuestado | ídem | `partial` con `authorized_total` mayor al total del ítem, o `authorized_quantity` mayor | 422 | `INSURER_AUTHORIZATION_EXCEEDS_BUDGET` |
| Parcial sin importe, sin IVA indicado o sin medio | ídem | — | 422 | `VALIDATION_ERROR` |
| Nueva versión igual o menor | `POST` de nuevo | otra decisión, sin subir importes | 201 | `version` 2; la anterior queda `superseded` |
| Nueva versión que sube un importe sin documento | ídem | `authorized` donde antes era `partial` | 422 | `INSURER_AUTHORIZATION_EVIDENCE_REQUIRED` |
| Con documento | ídem | `"document_ids": [{{claim_document_id}}]` | 201 | `documents` lo trae, con `insurer_authorization_id` |
| Documento de otro siniestro o ya vinculado | ídem | `document_ids` ajeno | 422 | `INSURER_AUTHORIZATION_DOCUMENT_MISMATCH` |
| Dar de baja el documento vinculado | `DELETE {{base_url}}/claims/{{claim_id}}/documents/{{claim_document_id}}` | `{ "reason": "..." }` | 409 | `CLAIM_DOCUMENT_IN_USE` |
| Versiones | `GET {{base_url}}/budgets/{{budget_id}}/insurer-authorizations` | — | 200 | Todas, por versión |
| Detalle | `GET {{base_url}}/budgets/{{budget_id}}/insurer-authorizations/{{insurer_authorization_id}}` | — | 200 | Con otro presupuesto en la URL → 404 |
| Detalle del presupuesto | `GET {{base_url}}/budgets/{{budget_id}}` | — | 200 | `insurer_authorization` con la vigente (sin el permiso, la clave no viene) |
| Reabrir o cancelar el presupuesto | `POST {{base_url}}/budgets/{{budget_id}}/reject` y `/reopen`, o `/cancel` | — | 200 | La vigente queda `invalidated` con el motivo |
| Sin permiso | cajero: registrar; mecánico o depósito: listar | — | 403 | `FORBIDDEN` |

Bloqueo de la OT, re-aceptación y excepciones internas (M7, ver `CLAUDE.md` 6.5 y 6.26). Con el presupuesto de arriba
(líneas `insurer`, siniestro abierto). Importes sin IVA.

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Aprobar antes de la autorización | `POST {{base_url}}/budgets/{{budget_id}}/approve` | `{ "channel": "whatsapp" }` | 200 | `client_acceptances[].owner_amount` = solo las líneas del dueño; `insurer_authorization_id` `null` |
| Crear la OT sin autorización | `POST {{base_url}}/work-orders` | `{ "budget_id": {{budget_id}}, "mechanic_id": {{mechanic_id}} }` | 422 | `WORK_ORDER_INSURER_AUTHORIZATION_REQUIRED` |
| Excepción para iniciar sin autorización (jefe de taller) | `POST {{base_url}}/internal-exceptions` | `{ "type": "start_without_authorization", "subject_id": {{budget_id}}, "reason": "El perito confirmó por teléfono." }` | 201 | `subject.type` `budget`, `consumed_at` `null` → `internal_exception_id` |
| Otra igual sin usar | ídem | ídem | 409 | `INTERNAL_EXCEPTION_ALREADY_GRANTED` |
| Con importe, o motivo de menos de 10 caracteres | ídem | `"amount": 1000` / `"reason": "Corto"` | 422 | `VALIDATION_ERROR` |
| Presupuesto ya autorizado, sin siniestro, sin líneas `insurer` o con OT | ídem | — | 422 | `INTERNAL_EXCEPTION_SUBJECT_INVALID` |
| Crear la OT con la excepción | `POST {{base_url}}/work-orders` | `{ …, "internal_exception_id": {{internal_exception_id}} }` | 201 | `GET internal-exceptions/{{internal_exception_id}}` trae `consumed_by` (`work_order`, código `OT-`) |
| Excepción de otro presupuesto, o innecesaria (ya autorizado, particular) | ídem | — | 422 | `INTERNAL_EXCEPTION_NOT_APPLICABLE` |
| La autorización baja después de aprobar (Ejemplo 3) | registrar una versión con menos y crear la OT | — | 422 | `WORK_ORDER_CLIENT_ACCEPTANCE_OUTDATED` (la excepción no lo resuelve) |
| Re-aceptación | `POST {{base_url}}/budgets/{{budget_id}}/client-acceptances` | `{ "channel": "phone", "contact": "Ana Gómez", "notes": "Acepta la diferencia." }` | 201 | `kind.value` `re_acceptance`; `owner_amount` calculado (sin OT, por el presupuesto; con la OT en curso, por la ejecución sin extras); `insurer_authorization_id` y `deductible_amount` vigentes |
| Re-aceptación sin medio | ídem | `{}` | 422 | `VALIDATION_ERROR` en `channel` |
| Re-aceptación con el presupuesto enviado, o con la OT completada o cancelada | ídem | ídem | 422 | `BUDGET_NOT_REACCEPTABLE` |
| Historial de respuestas | `GET {{base_url}}/budgets/{{budget_id}}/client-acceptances` | — | 200 | Aprobaciones, rechazos y re-aceptaciones, en orden |
| Advertencia de B | `GET {{base_url}}/work-orders/{{work_order_id}}` tras consumir más de lo autorizado o subir el deducible del siniestro | — | 200 | `owner_amount_warning` con `accepted_amount`, `potential_amount`, `difference`, `amount_exceeded`, `deductible_increased` y `message`; `null` si no hay nada que advertir. Con el token del mecánico la clave no viene |
| Extra en una OT con siniestro (P5) | `POST {{base_url}}/work-orders/{{work_order_id}}/parts` | el de siempre | 201 | `meta.warnings[0]`: "Este repuesto quedará a cargo del dueño…"; el detalle de la OT trae `has_claim` `true` |
| Absorción del taller | `POST {{base_url}}/internal-exceptions` | `{ "type": "workshop_absorption", "subject_id": {{work_order_id}}, "amount": 50000, "reason": "Daño causado en el taller." }` | 201 | `subject.type` `work_order`, `amount` `50000.00`; sin `amount` → 422; en una OT particular → `INTERNAL_EXCEPTION_SUBJECT_INVALID`. Se usa en el reparto (M8) |
| Listado | `GET {{base_url}}/internal-exceptions?type=…&subject_type=budget&subject_id=…&consumed=0` | — | 200 | Con `meta.pagination` |
| Sin permiso | recepcionista o cajero: otorgar; recepcionista, depósito o mecánico: listar; jefe de taller: re-aceptar | — | 403 | `FORBIDDEN` |
| Particular | aprobar y crear la OT de un presupuesto sin líneas `insurer` | — | 200 / 201 | Como siempre: `owner_amount` = subtotal, `has_claim` `false`, `owner_amount_warning` `null`, extras sin `meta` |
| Re-aceptación con la OT completada (corrección en M8) | `POST {{base_url}}/budgets/{{budget_id}}/client-acceptances` | `{ "channel": "phone" }` | 201 | Toma lo ejecutado sin los extras; con la OT cancelada → `BUDGET_NOT_REACCEPTABLE` |

Reparto económico (M8, ver `CLAUDE.md` 6.27). Con la OT de arriba **completada** (cajero; importes sin IVA salvo el
deducible).

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Generar el borrador | `POST {{base_url}}/work-orders/{{work_order_id}}/allocations` | — | 201 | `version` 1, `status` `draft`, `lines` propuestas (cubierto hasta lo autorizado, resto `owner_uncovered`, dueño y extras `owner_responsibility`), `summary` por responsable, `checks` y `concepts` (lo vendido por concepto: `base_total`, `coverable`, `authorized_total`) → `allocation_id` |
| Otro borrador | ídem | — | 409 | `ALLOCATION_DRAFT_EXISTS` |
| OT sin completar / particular | ídem | — | 422 | `WORK_ORDER_NOT_ALLOCATABLE` / `ALLOCATION_NOT_REQUIRED` |
| Editar | `PATCH {{base_url}}/work-orders/{{work_order_id}}/allocations/{{allocation_id}}` | `{ "lines": [{ "source_type": "work_order_item", "source_id": 1, "portion_type": "owner_uncovered", "amount": 200000 }, …], "deductible_amount": 0, "notes": "…" }` | 200 | `lines` reemplaza todas las porciones; `checks` se recalcula |
| Concepto ajeno o tipo repetido | ídem | — | 422 | `ALLOCATION_LINE_MISMATCH` |
| Absorción | ídem, con una porción `workshop_absorbed` e `internal_exception_id` de una excepción `workshop_absorption` de la OT | — | 200 | Al confirmar se consume (`GET internal-exceptions/{id}` trae `consumed_by.type` `work_order_allocation`) |
| Confirmar | `POST {{base_url}}/work-orders/{{work_order_id}}/allocations/{{allocation_id}}/confirm` | — | 200 | `status` `confirmed`; `checks` `null`; la confirmada anterior queda `superseded` |
| Reglas al confirmar | ídem, con el borrador incumpliendo | — | 422 / 409 | `CLAIM_DEDUCTIBLE_UNKNOWN`, `CLAIM_DEDUCTIBLE_NOT_ACCEPTED`, `ALLOCATION_INCOMPLETE`, `ALLOCATION_TOTAL_MISMATCH` (409), `ALLOCATION_EXTRA_NOT_COVERABLE`, `ALLOCATION_EXCEEDS_AUTHORIZATION`, `ALLOCATION_ABSORPTION_NOT_AUTHORIZED`, `ALLOCATION_OWNER_AMOUNT_NOT_ACCEPTED`, `ALLOCATION_DEDUCTIBLE_EXCEEDS_CLAIM` |
| Editar o eliminar la confirmada | `PATCH` / `DELETE …/allocations/{{allocation_id}}` | — | 422 | `ALLOCATION_NOT_EDITABLE` |
| Nueva versión | `POST …/allocations` con una confirmada | — | 201 | `version` 2, copia de la confirmada |
| Descartar el borrador | `DELETE …/allocations/{id del borrador}` | — | 200 | Después, `GET` → 404 |
| Versiones | `GET {{base_url}}/work-orders/{{work_order_id}}/allocations` | — | 200 | Todas, por versión. Con otra OT en la URL → 404 |
| Detalle de la OT | `GET {{base_url}}/work-orders/{{work_order_id}}` | — | 200 | `requires_allocation` `true`; `allocation` con el resumen de la confirmada (sin el permiso, la clave no viene) |
| Facturar la OT entera | `POST {{base_url}}/work-orders/{{work_order_id}}/invoice` | — | 422 | `WORK_ORDER_REQUIRES_ALLOCATION_INVOICING` (se factura por responsable en M9) |
| Cancelar el siniestro con lo cubierto confirmado | `POST {{base_url}}/claims/{{claim_id}}/cancel` | `{ "reason": "…" }` | 422 | `CLAIM_HAS_INSURER_ALLOCATIONS` |
| Bajar el deducible por debajo de lo asignado | `PATCH {{base_url}}/claims/{{claim_id}}` | `{ "deductible_amount": 0 }` | 422 | `CLAIM_DEDUCTIBLE_BELOW_ALLOCATED` |
| Sin permiso | recepcionista o jefe de taller: generar, editar, confirmar o eliminar; depósito o mecánico: listar | — | 403 | `FORBIDDEN` |

Facturas por responsable (M9, ver `CLAUDE.md` 6.29). Con el reparto **confirmado** de arriba (`{{allocation_id}}`), el
deducible del siniestro informado e igual a 0 y el token del cajero.

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Factura a la aseguradora | `POST {{base_url}}/work-orders/{{work_order_id}}/allocations/{{allocation_id}}/invoices` | `{ "client_id": {{insurer_client_id}} }` | 201 | Borrador a crédito (vencimiento hoy + plazo de la aseguradora), `allocation_id`, una línea por porción con `allocation_line_id`; lo parcial va con cantidad 1 y la cantidad real en la descripción |
| Factura al dueño | ídem | `{ "client_id": {{client_id}} }` | 201 | Borrador al contado |
| Otra al mismo cliente | ídem | ídem | 409 | `WORK_ORDER_ALREADY_INVOICED` |
| Cliente sin porciones | ídem | otro `client_id` | 422 | `ALLOCATION_CLIENT_NOT_RESPONSIBLE` |
| Reparto en borrador o sustituido | `POST …/allocations/{id}/invoices` | — | 422 | `ALLOCATION_NOT_CONFIRMED` |
| Deducible mayor a 0 / desconocido | ídem, después de `PATCH claims/{{claim_id}}` | — | 422 | `ALLOCATION_DEDUCTIBLE_MODE_UNDEFINED` / `CLAIM_DEDUCTIBLE_UNKNOWN`; también al emitir |
| Emitir | `POST {{base_url}}/invoices/{id}/issue` | — | 200 | Revalida reparto confirmado (`ALLOCATION_SUPERSEDED`, 409), deducible y R8 (`INVOICE_EXCEEDS_ALLOCATION`, 409). El PDF de la de la aseguradora trae siniestro y póliza |
| Cambiar el deducible con factura a la aseguradora | `PATCH {{base_url}}/claims/{{claim_id}}` | `{ "deductible_amount": 100000 }` | 422 | `CLAIM_DEDUCTIBLE_LOCKED` |
| Corregir el reparto con facturas | `POST …/allocations/{nueva}/confirm` | — | 409 | `ALLOCATION_HAS_INVOICES` con `errors.invoices[]` (número, destinatario, estado, pagos y acreditado). Se resuelve eliminando los borradores y acreditando (16.5.1) o anulando las emitidas |
| Detalle de la OT | `GET {{base_url}}/work-orders/{{work_order_id}}` | — | 200 | `invoices[]` e `invoices_count` (con `invoices.show`); `invoice` es la clave heredada (la última) |
| Listado por OT | `GET {{base_url}}/invoices?work_order_id={{work_order_id}}` | — | 200 | Las dos facturas |
| Entregar sin la factura de la aseguradora | `POST {{base_url}}/receptions/{{reception_id}}/delivery` | el de 17.3 | 422 | `DELIVERY_WORK_ORDER_NOT_FULLY_INVOICED` |
| Entregar con la del dueño al contado impaga | ídem | — | 422 | `DELIVERY_INVOICE_NOT_PAID`; cobrada, se entrega aunque la de la aseguradora (a crédito) esté impaga |
| Entregar con deducible mayor a 0 o desconocido | ídem | — | 422 | `DELIVERY_CLAIM_DEDUCTIBLE_PENDING` (toda la recepción) |
| Historial del vehículo | `GET {{base_url}}/vehicles/{{vehicle_id}}/history` | — | 200 | `invoices[]` en cada OT y `summary.by_recipient` (`owner`/`others`) |
| Sin permiso | recepcionista: generar | — | 403 | `FORBIDDEN` |

Reportes del módulo de aseguradoras (M11, ver `CLAUDE.md` 6.30). Con el siniestro y las facturas de arriba.

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Cuentas por cobrar | `GET {{base_url}}/reports/receivables` | — | 200 | `as_of`, `totals` (saldo, vencido, antigüedad), `by_recipient` (`insurer` y `customer`) y `clients` |
| Solo aseguradoras | `GET {{base_url}}/reports/receivables?recipient_type=insurer` | — | 200 | Una fila en `by_recipient` |
| Detalle de un cliente | `GET {{base_url}}/reports/receivables?client_id={{client_id}}` | — | 200 | Además `invoices` (vencimiento, días vencido, siniestro, cobrado, acreditado, saldo) |
| Tipo inválido | `GET {{base_url}}/reports/receivables?recipient_type=x` | — | 422 | `VALIDATION_ERROR` |
| Exportar | `GET {{base_url}}/reports/receivables?format=csv` (o `pdf`) | — | 200 | `reporte-cuentas-por-cobrar.csv` |
| Resumen del siniestro | `GET {{base_url}}/claims/{{claim_id}}/summary` | — | 200 | `claim`, `budgets` (sin IVA, totales de los aprobados), `allocations` (sin IVA), `billing` (con IVA, por destinatario) y `alerts` |
| Resumen como jefe de taller | ídem | — | 200 | Sin `billing` |
| Reporte de siniestros | `GET {{base_url}}/reports/claims?date_from=...&date_to=...&insurer_id=...&status=open` | — | 200 | Una fila por siniestro (fecha del siniestro en el período) y `totals` |
| Exportar | `GET {{base_url}}/reports/claims?format=csv` (o `pdf`) | — | 200 | Descarga |
| Sin permiso | recepcionista o jefe de taller: `reports/receivables` o `reports/claims`; mecánico: `claims/{id}/summary` | — | 403 | `FORBIDDEN` |

### M.5 Vehículos por cliente (rutas anidadas)

Complementan a `/vehicles`. El propietario se toma de la URL.

**M.5.1 Alta anidada**

`POST {{base_url}}/clients/{{client_id}}/vehicles`

```json
{
  "client_id": {{client2_id}},
  "brand_id": {{brand_id}},
  "vehicle_model_id": {{model_id}},
  "plate": "QAB456",
  "no_plate": false,
  "year": 2021
}
```

Esperado: `201` → `vehicle2_id`. El vehículo queda con `client_id` = `{{client_id}}`: el `client_id` del body se ignora.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Chapa repetida | mismo body | 422 | `VALIDATION_ERROR` (`errors.plate`) |
| Body vacío | `{}` | 422 | `VALIDATION_ERROR` (`brand_id`, `vehicle_model_id`, `no_plate`; **no** `client_id`) |
| Modelo de otra marca | `vehicle_model_id` de un modelo que no pertenece a `brand_id` | 422 | `VALIDATION_ERROR` (`errors.vehicle_model_id`) |

**M.5.2 Listado del cliente**

`GET {{base_url}}/clients/{{client_id}}/vehicles`

Esperado: `200`, solo los vehículos del cliente (`QAA123`, `QAB456`), con `meta.pagination`.

`GET {{base_url}}/clients/{{client_id}}/vehicles?search=QAB` → `200`, solo `QAB456`.

**M.5.3 Detalle**

| Caso | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| Vehículo del cliente | `GET {{base_url}}/clients/{{client_id}}/vehicles/{{vehicle2_id}}` | 200 | — |
| Vehículo de otro cliente | `GET {{base_url}}/clients/{{client2_id}}/vehicles/{{vehicle2_id}}` | 404 | `RESOURCE_NOT_FOUND` |
| Cliente inexistente | `GET {{base_url}}/clients/999999/vehicles` | 404 | `RESOURCE_NOT_FOUND` |
| Editar por ruta anidada | `PATCH {{base_url}}/clients/{{client_id}}/vehicles/{{vehicle2_id}}` | 405 | `METHOD_NOT_ALLOWED` (editar se hace por `/vehicles/{id}`) |

**M.5.4 Permisos del mecánico** (token = `{{token_mec}}`)

| Caso | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| Listar | `GET {{base_url}}/clients/{{client_id}}/vehicles` | 200 | — |
| Detalle | `GET {{base_url}}/clients/{{client_id}}/vehicles/{{vehicle2_id}}` | 200 | — |
| Alta | `POST {{base_url}}/clients/{{client_id}}/vehicles` (body de M.5.1 con `"plate": "QAC789"`) | 403 | `FORBIDDEN` |

### M.6 Crear recepción

`POST {{base_url}}/receptions`

```json
{
  "client_id": {{client_id}},
  "vehicle_id": {{vehicle_id}},
  "service_category_ids": [1, 2],
  "reception_date": "2026-10-10",
  "fuel_level": "half",
  "mileage": 45000,
  "problem_description": "Cambio de aceite y revision general"
}
```

Esperado: `201` → `reception_id`. La respuesta trae `reception_date` `"2026-10-10"` (Y-m-d), `mileage` `45000`
(entero), `vehicle.brand` / `vehicle.model` y `check_list.id` → `reception_check_list_id` (para editar el checklist).

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Sin categorías | `"service_category_ids": []` | 422 | `VALIDATION_ERROR` |
| Entrega anterior al ingreso | `"estimated_delivery_date": "2026-10-01"` | 422 | `VALIDATION_ERROR` |
| Vehículo con recepción abierta | repetir el mismo body (el vehículo ya tiene `{{reception_id}}` abierta) | 422 | `VALIDATION_ERROR` (`errors.vehicle_id`: "El vehículo ya tiene una recepción activa.") |
| Recepción inexistente | `GET {{base_url}}/receptions/999999` | 404 | `RESOURCE_NOT_FOUND` |

> Recepción **abierta** = cualquier estado salvo `delivered` y `cancelled` (`pending`, `in_progress`, `repair`, `completed`). Un vehículo no puede tener dos abiertas.

> **Estado derivado del flujo:** la recepción nace `pending` y cambia sola: crear un presupuesto → `in_progress` ("En proceso"); iniciar una OT → `repair` ("En reparación"); completar sus OT → `completed` ("Terminado"); entregar → `delivered`. Se verifica en P.1, O.3 y O.4. Ver el estado en `GET {{base_url}}/receptions/{{reception_id}}` → `data.status`.

### M.6b Cancelar una recepción

Crear una recepción descartable con el segundo vehículo del cliente (body de M.6 con `"vehicle_id": {{vehicle2_id}}`) → `201` → `reception_cancel_id`, `status.value` `pending`.

`POST {{base_url}}/receptions/{{reception_cancel_id}}/cancel`

```json
{ "reason": "El cliente desistio" }
```

Esperado: `200`, `status.value` `cancelled` ("Cancelado"), `cancellation_reason` informado, `cancelled_at` y `cancelled_by` informados.

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Sin motivo | `POST .../receptions/{{reception_cancel_id}}/cancel` | `{}` | 422 | `VALIDATION_ERROR` (`errors.reason`) |
| Cancelar otra vez | mismo endpoint | `{ "reason": "x" }` | 422 | `RECEPTION_NOT_CANCELLABLE` |
| Presupuestar sobre la cancelada | `POST {{base_url}}/budgets` | `{ "reception_id": {{reception_cancel_id}} }` | 422 | `RECEPTION_CLOSED` |
| Diagnosticar sobre la cancelada | `POST {{base_url}}/diagnostics` | `{ "reception_id": {{reception_cancel_id}}, "mechanic_id": {{mechanic_id}}, "requires_parts": false, "requires_repair": true }` | 422 | `RECEPTION_CLOSED` |
| Editar la cancelada (o entregada) | `PATCH {{base_url}}/receptions/{{reception_cancel_id}}` | `{ "observations": "Nota" }` | 422 | `RECEPTION_CLOSED` |
| Sus presupuestos borrador o enviados | `GET {{base_url}}/budgets?reception_id={{reception_cancel_id}}` | — | 200 | Quedaron `cancelled` al cancelar la recepción |
| Con OT vigentes | sobre una recepción con una OT no cancelada (por ejemplo `{{reception_id}}` después de O.1) | `{ "reason": "x" }` | 422 | `RECEPTION_HAS_ACTIVE_WORK_ORDERS` |
| Mecánico (token = `{{token_mec}}`) | `POST .../receptions/{{reception_id}}/cancel` | `{ "reason": "x" }` | 403 | `FORBIDDEN` |

### M.6c Checklist y diagnóstico

Checklist: `GET {{base_url}}/reception-check-lists/{{reception_check_list_id}}` (id en `check_list.id` de M.6) →
`items[]` con `id`, `name`, `type`, `required`, `value`, `observation`. Para cargar valores:
`PATCH` al mismo endpoint con `{ "items": [{ "id": <id del ítem>, "value": true, "observation": null }] }` → `200`,
`status` recalculado.

Diagnóstico: `POST {{base_url}}/diagnostics` con `{ "reception_id": {{reception_id}}, "mechanic_id": {{mechanic_id}},
"requires_parts": false, "requires_repair": true }` → `201`, `priority` `medium` por defecto.

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Prioridad vacía | `POST {{base_url}}/diagnostics` | `{ ..., "priority": null }` | 422 | `VALIDATION_ERROR` (`errors.priority`) |
| Vaciar textos y mecánico | `PATCH {{base_url}}/diagnostics/{id}` | `{ "mechanic_id": null, "diagnosis": null }` | 200 | Ambos en `null` |
| Severidad vacía en un ítem | `PATCH {{base_url}}/diagnostic-items/{id}` | `{ "severity": null }` | 422 | `VALIDATION_ERROR` (`errors.severity`) |
| Terminar el diagnóstico | `PATCH {{base_url}}/diagnostics/{id}` | `{ "status": "completed" }` | 200 | — |
| Ítem en un diagnóstico terminado o cancelado | `POST {{base_url}}/diagnostic-items` (también editar, eliminar y sus fotos) | `{ "diagnostic_id": {id}, ... }` | 422 | `DIAGNOSTIC_NOT_EDITABLE` |
| Volver a "en proceso" | `PATCH {{base_url}}/diagnostics/{id}` | `{ "status": "in_progress" }` | 200 | Los ítems vuelven a editarse |
| Listado | `GET {{base_url}}/diagnostics` | — | 200 | Ordenado por `diagnosed_at` descendente |

> Una recepción cancelada no cuenta como abierta: el vehículo puede volver a ingresar.

### M.6d Catálogo de ítems de diagnóstico

`POST {{base_url}}/diagnostic-item-templates` con `{ "title": "Pastillas de freno delanteras", "severity": "high",
"requires_replacement": true, "estimated_time": 60, "recommendation": "Reemplazar las pastillas." }` → `201`, `code`
`DIA-…`, `status.value` `active`. Guardar `data.id` → `template_id`.

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Listado y filtros | `GET {{base_url}}/diagnostic-item-templates?search=pastillas&status=active&category_id=` | — | 200 | Ordenado por título |
| Título repetido | `POST {{base_url}}/diagnostic-item-templates` | mismo body | 422 | `VALIDATION_ERROR` (`errors.title`) |
| Ítem desde el catálogo | `POST {{base_url}}/diagnostic-items` | `{ "diagnostic_id": {id}, "diagnostic_item_template_id": {{template_id}} }` | 201 | Título, severidad, banderas, tiempo y recomendación copiados; `status` `pending`; `template.code` |
| Pisar lo sugerido | `POST {{base_url}}/diagnostic-items` | `{ ..., "diagnostic_item_template_id": {{template_id}}, "severity": "critical" }` | 201 | `severity` `critical` |
| Sin ítem del catálogo | `POST {{base_url}}/diagnostic-items` | `{ "diagnostic_id": {id}, "title": "Otro", "severity": "low", ... }` | 422 | `VALIDATION_ERROR` (`errors.diagnostic_item_template_id` y `errors.title`: el ítem se elige siempre del catálogo) |
| Cambiar el ítem al editar | `PATCH {{base_url}}/diagnostic-items/{id}` | `{ "title": "Otro" }` o `{ "diagnostic_item_template_id": 5 }` | 422 | `VALIDATION_ERROR`: se elimina el ítem y se agrega otro |
| Desactivar | `POST {{base_url}}/diagnostic-item-templates/{{template_id}}/deactivate` | — | 200 | `status.value` `inactive` (repetir → 422 `DIAGNOSTIC_ITEM_TEMPLATE_INVALID_STATUS_TRANSITION`) |
| Usar uno inactivo | `POST {{base_url}}/diagnostic-items` | `{ "diagnostic_id": {id}, "diagnostic_item_template_id": {{template_id}} }` | 422 | `DIAGNOSTIC_ITEM_TEMPLATE_INACTIVE` |
| Eliminar en uso | `DELETE {{base_url}}/diagnostic-item-templates/{{template_id}}` | — | 200 | Los ítems conservan sus datos; `template.deleted` `true` |
| Mecánico (token = `{{token_mec}}`) | `GET` lista / `POST` alta | — | 200 / 403 | Consulta y usa el catálogo; no lo modifica (`FORBIDDEN`) |

### M.7 Obtener mecánico

`GET {{base_url}}/mechanics`

Esperado: `200`. Guardar `data[0].id` → `mechanic_id`.

---

## FASE BASE — Presupuestos

Token: administrador.

### P.1 Crear presupuesto

`POST {{base_url}}/budgets`

```json
{ "reception_id": {{reception_id}} }
```

Esperado: `201`, `status` `draft`, código `BUD-XXXXXX` → `budget_id`.

Estado de la recepción: `GET {{base_url}}/receptions/{{reception_id}}` → `status.value` `in_progress` ("En proceso"). Desde ahora la recepción está procesada: `PATCH {{base_url}}/receptions/{{reception_id}}` con `{ "service_category_ids": [1] }` → `422` `RECEPTION_CATEGORIES_NOT_EDITABLE`; `DELETE {{base_url}}/receptions/{{reception_id}}` → `422` `RECEPTION_NOT_DELETABLE` (no lo ejecutes si no es procesada: la eliminaría).

### P.2 Agregar ítem de mano de obra

`POST {{base_url}}/budgets/{{budget_id}}/items`

```json
{
  "type": "labor",
  "description": "Mano de obra cambio de aceite",
  "quantity": 1,
  "unit_price": 500
}
```

Esperado: `201` → `budget_item_id`; el presupuesto recalcula `subtotal`, `tax` y `total`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Cantidad cero | `{ ..., "quantity": 0 }` | 422 | `VALIDATION_ERROR` |
| Tipo inválido | `{ ..., "type": "otro" }` | 422 | `VALIDATION_ERROR` |

Permisos del mecánico (token = `{{token_mec}}`):

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Listar ítems | `GET {{base_url}}/budgets/{{budget_id}}/items` | — | 403 | `FORBIDDEN` |
| Ver ítem | `GET {{base_url}}/budgets/{{budget_id}}/items/{{budget_item_id}}` | — | 403 | `FORBIDDEN` |
| Crear ítem | `POST {{base_url}}/budgets/{{budget_id}}/items` | body de P.2 | 403 | `FORBIDDEN` |
| Editar ítem | `PATCH {{base_url}}/budgets/{{budget_id}}/items/{{budget_item_id}}` | `{ "unit_price": 1 }` | 403 | `FORBIDDEN` |
| Eliminar ítem | `DELETE {{base_url}}/budgets/{{budget_id}}/items/{{budget_item_id}}` | — | 403 | `FORBIDDEN` |

### P.3 Estados del presupuesto

| Paso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Aprobar en `draft` | `POST {{base_url}}/budgets/{{budget_id}}/approve` | — | 422 | `BUDGET_NOT_APPROVABLE` |
| Rechazar en `draft` | `POST {{base_url}}/budgets/{{budget_id}}/reject` | — | 422 | `BUDGET_NOT_REJECTABLE` |
| Reabrir no rechazado | `POST {{base_url}}/budgets/{{budget_id}}/reopen` | — | 422 | `BUDGET_NOT_REOPENABLE` |
| Enviar con diagnósticos pendientes o en proceso en la recepción | `POST {{base_url}}/budgets/{{budget_id}}/send` | — | 422 | `BUDGET_DIAGNOSTICS_PENDING` (el mensaje lista los números); terminarlos o cancelarlos |
| Enviar | `POST {{base_url}}/budgets/{{budget_id}}/send` | — | 200 | `sent` |
| Enviar de nuevo | `POST {{base_url}}/budgets/{{budget_id}}/send` | — | 422 | `BUDGET_NOT_SENDABLE` |
| Agregar ítem con `sent` | `POST {{base_url}}/budgets/{{budget_id}}/items` | body de P.2 | 422 | `BUDGET_NOT_EDITABLE` |
| Aprobar | `POST {{base_url}}/budgets/{{budget_id}}/approve` | — (body opcional, ver abajo) | 200 | `approved`, `approved_at` informado; `client_acceptances` con una fila `approval` (`decided_by` = usuario del token, `channel` `null`, `owner_amount` = subtotal) |
| Cancelar aprobado | `POST {{base_url}}/budgets/{{budget_id}}/cancel` | — | 422 | `BUDGET_NOT_CANCELLABLE` |

Caso adicional (presupuesto nuevo y vacío): crear con P.1 y enviar sin ítems → `422 BUDGET_WITHOUT_ITEMS`; el presupuesto sigue en `draft`.

Respuesta del cliente (`budget_client_acceptances`, ver `docs/handoff.md`, 2.57). `approve` y `reject` aceptan un body
opcional; sin body funcionan como siempre y la fila se registra igual:

```json
{ "channel": "whatsapp", "contact": "+595981123456", "notes": "Aprobó por mensaje." }
```

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Aprobar con datos | `POST {{base_url}}/budgets/{{budget_id}}/approve` | el de arriba | 200 | `client_acceptances[0]`: `kind.value` `approval`, `channel.label` `WhatsApp`, `contact`, `notes` |
| Rechazar con motivo | `POST {{base_url}}/budgets/{{budget_id}}/reject` | `{ "channel": "phone", "notes": "Le pareció caro." }` | 200 | Fila `rejection` |
| Canal inválido | ídem | `{ "channel": "fax" }` | 422 | `VALIDATION_ERROR` en `channel` |
| Notas muy cortas | ídem | `{ "notes": "x" }` | 422 | `VALIDATION_ERROR` en `notes` |
| Reabrir, reenviar y aprobar | `reopen` → editar → `send` → `approve` | — | 200 | `client_acceptances` con las dos filas en orden (`rejection`, `approval`), cada una con su `owner_amount` |

Importes grandes (presupuesto en `draft`; los importes van en `decimal(14,2)`, ver `docs/handoff.md`, 2.56):

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Ítem de 150.000.000 | `POST {{base_url}}/budgets/{{budget_id}}/items` | `{ "type": "labor", "description": "Chapería completa", "quantity": 1, "unit_price": 150000000 }` | 201 | Presupuesto con `subtotal` 150000000.00, `tax` 15000000.00, `total` 165000000.00 (antes: 500) |
| Precio sobre el máximo | ídem | `unit_price` 1000000000000 | 422 | `VALIDATION_ERROR` en `unit_price` |
| Cantidad sobre el máximo | ídem | `quantity` 100000000 | 422 | `VALIDATION_ERROR` en `quantity` |
| Total del ítem fuera de rango | ídem | `quantity` 2, `unit_price` 999999999999.99 | 422 | `BUDGET_AMOUNT_OUT_OF_RANGE`; el ítem no se guarda |
| Total con IVA fuera de rango | ídem | `quantity` 1, `unit_price` 909090909091 | 422 | `BUDGET_AMOUNT_OUT_OF_RANGE`; el ítem no se guarda y los totales no cambian |

Ciclo de rechazo (presupuesto nuevo con un ítem): enviar → `reject` (200, `rejected`) → `reopen` (200, `draft`).

---

## FASE BASE — Órdenes de trabajo

Token: administrador.

### O.1 Crear OT

`POST {{base_url}}/work-orders`

```json
{
  "budget_id": {{budget_id}},
  "mechanic_id": {{mechanic_id}}
}
```

Esperado: `201`, código `OT-XXXXXX`, `status.value` `pending`, un ítem por ítem del presupuesto → `work_order_id`, `data.items[0].id` → `work_order_item_id`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Mismo presupuesto otra vez | mismo body | 422 | `WORK_ORDER_ALREADY_EXISTS` |
| Presupuesto no aprobado | `budget_id` de un presupuesto en `draft` | 422 | `WORK_ORDER_BUDGET_NOT_APPROVED` |
| Mecánico inexistente | `"mechanic_id": 999999` | 422 | `VALIDATION_ERROR` |

### O.2 Editar OT

`PATCH {{base_url}}/work-orders/{{work_order_id}}`

```json
{ "notes": "Cliente espera en el taller" }
```

Esperado: `200`.

| Caso | Body | HTTP | Resultado / error_code |
|---|---|---|---|
| Intentar cambiar código/estado | `{ "code": "HACK", "status": "completed" }` | 200 | `code` y `status` sin cambios |
| Editar OT en progreso | `{ "notes": "x" }` (después de O.3) | 422 | `WORK_ORDER_NOT_EDITABLE` |

### O.3 Estados de la OT

Body vacío en todos los casos.

| Paso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Pausar en `pending` | `POST {{base_url}}/work-orders/{{work_order_id}}/pause` | 422 | `WORK_ORDER_NOT_PAUSABLE` |
| Iniciar ítem con OT `pending` | `POST {{base_url}}/work-orders/{{work_order_id}}/items/{{work_order_item_id}}/start` | 422 | `WORK_ORDER_NOT_IN_PROGRESS` |
| Iniciar | `POST {{base_url}}/work-orders/{{work_order_id}}/start` | 200 | `in_progress`; la recepción pasa a `repair` ("En reparación") |
| Iniciar otra vez | `POST {{base_url}}/work-orders/{{work_order_id}}/start` | 409 | `WORK_ORDER_NOT_STARTABLE` |
| Cancelar en progreso | `POST {{base_url}}/work-orders/{{work_order_id}}/cancel` | 422 | `WORK_ORDER_NOT_CANCELLABLE` |
| Reanudar sin pausar | `POST {{base_url}}/work-orders/{{work_order_id}}/resume` | 422 | `WORK_ORDER_NOT_RESUMABLE` |
| Pausar | `POST {{base_url}}/work-orders/{{work_order_id}}/pause` | 200 | `paused` |
| Completar pausada | `POST {{base_url}}/work-orders/{{work_order_id}}/complete` | 422 | `WORK_ORDER_NOT_COMPLETABLE` |
| Reanudar | `POST {{base_url}}/work-orders/{{work_order_id}}/resume` | 200 | `in_progress` |
| Completar con ítems pendientes | `POST {{base_url}}/work-orders/{{work_order_id}}/complete` | 422 | `WORK_ORDER_HAS_PENDING_ITEMS` |

### O.4 Ítems de la OT

| Paso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Completar ítem `pending` | `POST {{base_url}}/work-orders/{{work_order_id}}/items/{{work_order_item_id}}/complete` | 422 | `WORK_ORDER_ITEM_NOT_COMPLETABLE` |
| Iniciar ítem | `POST .../items/{{work_order_item_id}}/start` | 200 | `in_progress` |
| Iniciar de nuevo | `POST .../items/{{work_order_item_id}}/start` | 422 | `WORK_ORDER_ITEM_NOT_STARTABLE` |
| Cancelar ítem en progreso | `POST .../items/{{work_order_item_id}}/cancel` | 422 | `WORK_ORDER_ITEM_NOT_CANCELLABLE` |
| Completar ítem | `POST .../items/{{work_order_item_id}}/complete` | 200 | `completed` |
| Ítem de otra OT en la URL | `POST {{base_url}}/work-orders/{{work_order_id}}/items/999999/start` | 404 | `RESOURCE_NOT_FOUND` |

Completar todos los ítems y luego:

| Paso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Completar OT | `POST {{base_url}}/work-orders/{{work_order_id}}/complete` | 200 | `completed`, `completed_at` informado; la recepción pasa a `completed` ("Terminado") si no tiene otras OT vigentes |
| Iniciar OT completada | `POST {{base_url}}/work-orders/{{work_order_id}}/start` | 409 | `WORK_ORDER_NOT_STARTABLE` |
| Pausar OT completada | `POST {{base_url}}/work-orders/{{work_order_id}}/pause` | 422 | `WORK_ORDER_NOT_PAUSABLE` |

---

## FASE 10 — Catálogo de repuestos, categorías y proveedores

Token: administrador.

### 10.1 Crear categoría de repuestos

`POST {{base_url}}/part-categories`

```json
{
  "name": "QA Lubricantes",
  "description": "Categoria de pruebas"
}
```

Esperado: `201` → `category_id`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Nombre duplicado | mismo body | 422 | `VALIDATION_ERROR` |

### 10.2 Crear proveedor

`POST {{base_url}}/suppliers`

```json
{
  "name": "Proveedor QA",
  "tax_id": "80099999-9",
  "contact_name": "Mario Rojas",
  "phone": "+595 21 555 000",
  "email": "ventas@proveedorqa.com"
}
```

Esperado: `201` → `supplier_id`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| `tax_id` duplicado | mismo body | 422 | `VALIDATION_ERROR` |
| Nombre con carácter no permitido | `{ "name": "Proveedor @QA" }` | 422 | `VALIDATION_ERROR` (`errors.name`) |
| Dirección con carácter no permitido | `{ "name": "Proveedor Dir", "address": "Calle <falsa> 123" }` | 422 | `VALIDATION_ERROR` (`errors.address`) |
| Contacto con números | `{ "name": "Proveedor Contacto", "contact_name": "Juan 123" }` | 422 | `VALIDATION_ERROR` (`errors.contact_name`) |

Edición (`PATCH {{base_url}}/suppliers/{{supplier_id}}`): `{ "tax_id": null, "email": null, "notes": null }` → `200`
con esos campos vacíos; `{ "name": null }` → `422` (`errors.name`).

Caracteres admitidos (además de letras, números y espacios): nombre y dirección `. , # - / ° º & ( ) '`; nombre de contacto solo letras, espacios y `. ' -`.

Caso válido:

```json
{
  "name": "Garcia & Hijos (Repuestos) N° 2",
  "tax_id": "80099998-8",
  "contact_name": "Maria-Jose O'Neill",
  "address": "Av. Mcal. Lopez 1234 (frente al banco) - Km 5"
}
```

Esperado: `201`.

### 10.3 Crear repuesto (litros)

> Caracteres admitidos (además de letras, números y espacios):
> nombre `. , - / ( ) " ' + %` · marca `& . - /` · descripción `. , ; : ( ) - / # % + & " ' ° º`.

`POST {{base_url}}/parts`

```json
{
  "sku": "QA-ACE-001",
  "name": "Aceite QA",
  "brand": "Shell",
  "part_category_id": {{category_id}},
  "unit": "liter",
  "cost_price": 100,
  "sale_price": 150,
  "minimum_stock": 5
}
```

Esperado: `201`, `code` `REP-XXXXXX`, `status.value` `active`, `stock.on_hand` `"0.000"`, `stock.is_below_minimum` `true` → `part_id`.

### 10.4 Crear repuesto (unidades)

`POST {{base_url}}/parts`

```json
{
  "sku": "QA-FIL-001",
  "name": "Filtro QA",
  "brand": "Mann",
  "part_category_id": {{category_id}},
  "unit": "unit",
  "cost_price": 35000,
  "sale_price": 50000,
  "minimum_stock": 2
}
```

Esperado: `201` → `part_unit_id`.

Casos de error de creación:

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| SKU duplicado | body de 10.3 | 422 | `VALIDATION_ERROR` (`errors.sku`) |
| Unidad inválida | `{ ..., "sku": "QA-X-1", "unit": "barrel" }` | 422 | `VALIDATION_ERROR` (`errors.unit`) |
| Nombre con carácter no permitido | `{ ..., "sku": "QA-X-2", "name": "Aceite @oferta" }` | 422 | `VALIDATION_ERROR` (`errors.name`) |
| Marca con carácter no permitido | `{ ..., "sku": "QA-X-4", "brand": "Bosch!" }` | 422 | `VALIDATION_ERROR` (`errors.brand`) |
| Descripción con carácter no permitido | `{ ..., "sku": "QA-X-6", "description": "<script>" }` | 422 | `VALIDATION_ERROR` (`errors.description`) |
| Nombre con números y símbolos (válido) | `{ ..., "sku": "QA-X-5", "name": "Aceite 5W-30 (sintetico) 100%" }` | 201 | — |
| Marca y descripción reales (válido) | `{ ..., "sku": "QA-X-7", "brand": "K&N", "description": "Para motor 1.6 (2015-2020), 4 cil." }` | 201 | — |
| Precio negativo | `{ ..., "sku": "QA-X-3", "cost_price": -1 }` | 422 | `VALIDATION_ERROR` |
| Categoría inactiva | `part_category_id` de una categoría con `is_active: false` | 422 | `PART_CATEGORY_INACTIVE` |
| Body vacío | `{}` | 422 | `VALIDATION_ERROR` |

### 10.5 Editar repuesto (PATCH parcial)

`PATCH {{base_url}}/parts/{{part_id}}`

```json
{ "sale_price": 160 }
```

Esperado: `200`, `sale_price` `"160.00"`; el resto de los campos sin cambios.

| Caso | Body | HTTP | Resultado / error_code |
|---|---|---|---|
| Intentar cambiar código/estado | `{ "code": "HACK-1", "status": "discontinued" }` | 200 | `code` y `status` sin cambios |
| Vaciar opcionales | `{ "sku": null, "part_number": null, "description": null, "brand": null }` | 200 | Los cuatro en `null` |
| Stock mínimo vacío | `{ "minimum_stock": null }` | 422 | `VALIDATION_ERROR` (`errors.minimum_stock`; también en el alta) |

### 10.6 Consultar repuestos

| Caso | Método / Endpoint | HTTP | Resultado |
|---|---|---|---|
| Búsqueda | `GET {{base_url}}/parts?search=QA` | 200 | Solo repuestos QA |
| Por categoría | `GET {{base_url}}/parts?category_id={{category_id}}` | 200 | Filtrado |
| Por estado | `GET {{base_url}}/parts?status=active` | 200 | Filtrado |
| Detalle | `GET {{base_url}}/parts/{{part_id}}` | 200 | Incluye `category`, `suppliers`, `stock` |
| Inexistente | `GET {{base_url}}/parts/999999` | 404 | `RESOURCE_NOT_FOUND` |

### 10.7 Estados del repuesto

Crear un repuesto descartable (body de 10.4 con `"sku": "QA-EST-001"`, `"name": "Repuesto Estados"`) → `part_status_id`. Body vacío en todos los casos.

| Paso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Desactivar | `POST {{base_url}}/parts/{{part_status_id}}/deactivate` | 200 | `inactive` |
| Desactivar de nuevo | `POST {{base_url}}/parts/{{part_status_id}}/deactivate` | 422 | `PART_INVALID_STATUS_TRANSITION` |
| Activar | `POST {{base_url}}/parts/{{part_status_id}}/activate` | 200 | `active` |
| Descontinuar | `POST {{base_url}}/parts/{{part_status_id}}/discontinue` | 200 | `discontinued` |
| Activar descontinuado | `POST {{base_url}}/parts/{{part_status_id}}/activate` | 422 | `PART_DISCONTINUED` |
| Editar descontinuado | `PATCH {{base_url}}/parts/{{part_status_id}}` con `{ "sale_price": 1 }` | 422 | `PART_DISCONTINUED` |

### 10.8 Vincular proveedor a repuesto

`POST {{base_url}}/parts/{{part_id}}/suppliers`

```json
{
  "supplier_id": {{supplier_id}},
  "supplier_part_code": "SH-5W30",
  "last_cost": 95,
  "is_preferred": true
}
```

Esperado: `201`, `suppliers[0].pivot.is_preferred` `true`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Mismo proveedor otra vez | mismo body | 409 | `PART_SUPPLIER_ALREADY_ATTACHED` |
| Proveedor inactivo | `supplier_id` de un proveedor con `is_active: false` | 422 | `SUPPLIER_INACTIVE` |
| Proveedor inexistente | `"supplier_id": 999999` | 422 | `VALIDATION_ERROR` |

### 10.9 Editar vínculo

`PATCH {{base_url}}/parts/{{part_id}}/suppliers/{{supplier_id}}`

```json
{ "last_cost": 98 }
```

Esperado: `200`, `pivot.last_cost` `"98.00"`.

| Caso | Body | HTTP | Resultado / error_code |
|---|---|---|---|
| Vaciar código y costo | `{ "supplier_part_code": null, "last_cost": null }` | 200 | Ambos en `null` |
| Costo negativo | `{ "last_cost": -1 }` | 422 | `VALIDATION_ERROR` (`errors.last_cost`) |

### 10.10 Eliminaciones con integridad

| Caso | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| Proveedor con repuestos vinculados | `DELETE {{base_url}}/suppliers/{{supplier_id}}` | 409 | `SUPPLIER_HAS_PARTS` |
| Categoría con repuestos | `DELETE {{base_url}}/part-categories/{{category_id}}` | 409 | `PART_CATEGORY_HAS_PARTS` |
| Desvincular proveedor | `DELETE {{base_url}}/parts/{{part_id}}/suppliers/{{supplier_id}}` | 200 | — |

> Volver a ejecutar 10.8 después de desvincular: se usa en la FASE 11.

### 10.11 Permisos del mecánico (token = `{{token_mec}}`)

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Ver repuestos | `GET {{base_url}}/parts` | — | 200 | — |
| Crear repuesto | `POST {{base_url}}/parts` | body de 10.4 | 403 | `FORBIDDEN` |
| Cambiar estado | `POST {{base_url}}/parts/{{part_id}}/deactivate` | — | 403 | `FORBIDDEN` |
| Ver proveedores | `GET {{base_url}}/suppliers` | — | 403 | `FORBIDDEN` |

---

## FASE 11 — Inventario

Token: administrador. Repuesto de referencia: `{{part_id}}` (litros).

### 11.1 Stock inicial

`POST {{base_url}}/inventory/entries`

```json
{
  "part_id": {{part_id}},
  "type": "initial",
  "quantity": 10,
  "unit_cost": 100
}
```

Esperado: `201`, `type.value` `initial`, `quantity` `"10.000"`, `total_cost` `"1000.00"`, `balance_after` `"10.000"`, `average_cost_after` `"100.0000"`, `warehouse.code` `DEP-01`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Segundo stock inicial | mismo body | 409 | `INVENTORY_INITIAL_ALREADY_REGISTERED` |

### 11.2 Compra

Las compras entran solo por el módulo de compras (detalle completo en la FASE 21). Compra rápida para seguir con
esta fase: 5 litros a 143 con IVA 10% = 130 sin IVA.

1. `POST {{base_url}}/purchases` → `201`, guardar `data.id` → `purchase_id`:

```json
{
  "supplier_id": {{supplier_id}},
  "supplier_document_number": "A-0001-00000123",
  "supplier_document_date": "2026-10-20"
}
```

2. `POST {{base_url}}/purchases/{{purchase_id}}/items` → `201`, `items.0.net_unit_cost` `"130.0000"`:

```json
{
  "part_id": {{part_id}},
  "quantity": 5,
  "unit_cost": 143,
  "tax_rate": 10
}
```

3. `POST {{base_url}}/purchases/{{purchase_id}}/receive` → `200`, `status.value` `received`. Guardar
   `data.items.0.inventory_movement_id` → `movement_id`.

Esperado en `GET {{base_url}}/inventory/movements/{{movement_id}}`: `type.value` `purchase`, `balance_after` `"15.000"`,
`average_cost_after` `"110.0000"` ((10×100 + 5×130) / 15), `document_number` `A-0001-00000123`. En
`GET {{base_url}}/parts/{{part_id}}`, `suppliers[0].pivot.last_cost` pasa a `"130.00"`.

Casos de error de entradas manuales:

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Compra manual (ya no se admite) | `{ "part_id": {{part_id}}, "type": "purchase", "quantity": 1, "unit_cost": 100 }` | 422 | `VALIDATION_ERROR` (`errors.type`) |
| Tipo no permitido | `{ "part_id": {{part_id}}, "type": "work_order_return", "quantity": 1, "unit_cost": 1 }` | 422 | `VALIDATION_ERROR` (`errors.type`) |
| Cantidad cero / costo negativo | `{ "part_id": {{part_id}}, "type": "initial", "quantity": 0, "unit_cost": -1 }` | 422 | `VALIDATION_ERROR` |
| Más de 3 decimales | `{ "part_id": {{part_id}}, "type": "initial", "quantity": 1.2345, "unit_cost": 100 }` | 422 | `VALIDATION_ERROR` (`errors.quantity`) |
| Repuesto inexistente | `{ "part_id": 999999, "type": "initial", "quantity": 1, "unit_cost": 100 }` | 422 | `VALIDATION_ERROR` |

Fracciones, repuestos descontinuados y proveedores inactivos en compras: ver 21.5.

### 11.3 Stock del repuesto por unidad (para la FASE 12)

`POST {{base_url}}/inventory/entries`

```json
{
  "part_id": {{part_unit_id}},
  "type": "initial",
  "quantity": 4,
  "unit_cost": 35000
}
```

Esperado: `201`.

### 11.4 Salida (baja)

`POST {{base_url}}/inventory/exits`

```json
{
  "part_id": {{part_id}},
  "type": "loss",
  "quantity": 0.5,
  "reason": "Derrame en deposito"
}
```

Esperado: `201`, `quantity` `"-0.500"`, `unit_cost` `"110.0000"`, `total_cost` `"-55.00"`, `balance_after` `"14.500"`, `average_cost_after` sin cambios.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Más que el disponible | `{ "part_id": {{part_id}}, "type": "loss", "quantity": 1000, "reason": "Prueba" }` | 422 | `INSUFFICIENT_STOCK` (`errors.quantity` indica el disponible) |
| Devolución sin proveedor | `{ "part_id": {{part_id}}, "type": "supplier_return", "quantity": 1, "reason": "Fallado" }` | 422 | `VALIDATION_ERROR` (`errors.supplier_id`) |
| Sin motivo | `{ "part_id": {{part_id}}, "type": "loss", "quantity": 1 }` | 422 | `VALIDATION_ERROR` (`errors.reason`) |
| Tipo no permitido | `{ "part_id": {{part_id}}, "type": "work_order_out", "quantity": 1, "reason": "x" }` | 422 | `VALIDATION_ERROR` (`errors.type`) |

Devolución a proveedor válida:

```json
{
  "part_id": {{part_id}},
  "type": "supplier_return",
  "quantity": 0.5,
  "supplier_id": {{supplier_id}},
  "reason": "Envase defectuoso"
}
```

Esperado: `201`, `type.direction` `out`, `balance_after` `"14.000"`.

### 11.5 Ajuste por conteo físico

`POST {{base_url}}/inventory/adjustments`

```json
{
  "part_id": {{part_id}},
  "counted_quantity": 13.5,
  "reason": "Conteo fisico mensual"
}
```

Esperado: `201`, `type.value` `adjustment_out`, `quantity` `"-0.500"`, `balance_after` `"13.500"`.

Ajuste positivo con costo:

```json
{
  "part_id": {{part_id}},
  "counted_quantity": 15,
  "unit_cost": 110,
  "reason": "Sobrante encontrado"
}
```

Esperado: `201`, `type.value` `adjustment_in`, `quantity` `"1.500"`, `balance_after` `"15.000"`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Sin diferencia | `{ "part_id": {{part_id}}, "counted_quantity": 15, "reason": "Conteo" }` | 422 | `INVENTORY_NO_DIFFERENCE` |
| Conteo negativo | `{ "part_id": {{part_id}}, "counted_quantity": -1, "reason": "Conteo" }` | 422 | `VALIDATION_ERROR` |
| Sin motivo | `{ "part_id": {{part_id}}, "counted_quantity": 14 }` | 422 | `VALIDATION_ERROR` (`errors.reason`) |
| Por debajo de lo reservado | `counted_quantity` menor al reservado (ver FASE 12, paso 12.2) | 422 | `STOCK_BELOW_RESERVED` |

### 11.6 Consultas

| Caso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Stock general | `GET {{base_url}}/inventory/stocks` | 200 | `quantity_on_hand`, `quantity_reserved`, `quantity_available`, `average_cost`, `stock_value`, `is_below_minimum`, `part` |
| Búsqueda | `GET {{base_url}}/inventory/stocks?search=QA` | 200 | Solo repuestos QA |
| Bajo mínimo | `GET {{base_url}}/inventory/stocks?below_minimum=1` | 200 | Incluye `REF-REF-001` |
| Con stock por categoría | `GET {{base_url}}/inventory/stocks?with_stock=1&category_id={{category_id}}` | 200 | Filtrado |
| Movimientos por tipo | `GET {{base_url}}/inventory/movements?type=purchase` | 200 | Solo compras |
| Movimientos por fecha | `GET {{base_url}}/inventory/movements?date_from=2026-10-10&date_to=2026-10-10` | 200 | Filtrado |
| Historial del repuesto | `GET {{base_url}}/parts/{{part_id}}/movements` | 200 | Del más nuevo al más viejo |
| Detalle de movimiento | `GET {{base_url}}/inventory/movements/{{movement_id}}` | 200 | Incluye `supplier`, `warehouse`, `document_number` |
| Fecha mal formada | `GET {{base_url}}/inventory/movements?date_from=ayer` | 422 | `VALIDATION_ERROR` |
| Rango invertido | `GET {{base_url}}/inventory/movements?date_from=2026-10-10&date_to=2026-10-01` | 422 | `VALIDATION_ERROR` |
| Tipo inexistente | `GET {{base_url}}/inventory/movements?type=otro` | 422 | `VALIDATION_ERROR` |
| Movimiento inexistente | `GET {{base_url}}/inventory/movements/999999` | 404 | `RESOURCE_NOT_FOUND` |
| Editar movimiento | `PATCH {{base_url}}/inventory/movements/{{movement_id}}` | 405 | `METHOD_NOT_ALLOWED` |
| Eliminar movimiento | `DELETE {{base_url}}/inventory/movements/{{movement_id}}` | 405 | `METHOD_NOT_ALLOWED` |

### 11.7 Integridad

| Caso | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| Eliminar repuesto con stock | `DELETE {{base_url}}/parts/{{part_id}}` | 409 | `PART_HAS_STOCK` |

### 11.8 Permisos del mecánico (token = `{{token_mec}}`)

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Ver stock | `GET {{base_url}}/inventory/stocks` | — | 200 | — |
| Ver movimientos | `GET {{base_url}}/inventory/movements` | — | 200 | — |
| Registrar entrada | `POST {{base_url}}/inventory/entries` | body de 11.2 | 403 | `FORBIDDEN` |
| Registrar salida | `POST {{base_url}}/inventory/exits` | body de 11.4 | 403 | `FORBIDDEN` |
| Registrar ajuste | `POST {{base_url}}/inventory/adjustments` | body de 11.5 | 403 | `FORBIDDEN` |

---

## FASE 12 — Repuestos en la Orden de Trabajo

Token: administrador salvo indicación. Stock de partida de `{{part_id}}`: 15 L a promedio 110.

> En las rutas `.../parts/{{line_id}}`, el ID es el de la **línea de la OT**, no el del repuesto.

### 12.1 Presupuesto con repuesto vinculado al catálogo

1. `POST {{base_url}}/budgets` con `{ "reception_id": {{reception_id}} }` → `budget2_id`.
2. `POST {{base_url}}/budgets/{{budget2_id}}/items`:

```json
{
  "type": "part",
  "part_id": {{part_id}},
  "description": "Aceite QA",
  "quantity": 2,
  "unit_price": 150
}
```

Esperado: `201`, `part_id` = `{{part_id}}`, `part.code` informado.

3. `POST {{base_url}}/budgets/{{budget2_id}}/items`:

```json
{
  "type": "labor",
  "description": "Mano de obra",
  "quantity": 1,
  "unit_price": 500
}
```

Casos de error en ítems de presupuesto (sobre `{{budget2_id}}` en `draft`):

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| `part_id` en ítem que no es repuesto | `{ "type": "labor", "part_id": {{part_id}}, "description": "Mano de obra", "quantity": 1, "unit_price": 100 }` | 422 | `VALIDATION_ERROR` (`errors.part_id`) |
| Repuesto inexistente | `{ "type": "part", "part_id": 999999, "description": "X", "quantity": 1, "unit_price": 100 }` | 422 | `VALIDATION_ERROR` |
| Repuesto inactivo | `{ "type": "part", "part_id": <repuesto inactivo>, "description": "X", "quantity": 1, "unit_price": 100 }` | 422 | `PART_NOT_USABLE` |
| Fracción en repuesto por unidad | `{ "type": "part", "part_id": {{part_unit_id}}, "description": "Filtro", "quantity": 1.5, "unit_price": 100 }` | 422 | `INVENTORY_FRACTIONAL_QUANTITY_NOT_ALLOWED` |
| Ítem repuesto sin catálogo (válido) | `{ "type": "part", "description": "Repuesto especial", "quantity": 1, "unit_price": 100 }` | 201 | — (`part_id: null`) |

> Si se creó el ítem sin catálogo, eliminarlo con `DELETE {{base_url}}/budgets/{{budget2_id}}/items/{id}` para mantener los valores esperados de los pasos siguientes. Al cambiar un ítem a `"type": "service"` con `PATCH`, el `part_id` vuelve a `null`.

4. `POST {{base_url}}/budgets/{{budget2_id}}/send` → `200`.
5. `POST {{base_url}}/budgets/{{budget2_id}}/approve` → `200`.

### 12.2 Crear OT con reserva automática

`POST {{base_url}}/work-orders`

```json
{
  "budget_id": {{budget2_id}},
  "mechanic_id": {{mechanic_id}}
}
```

Esperado: `201` → `work_order2_id`; `data.parts[0].id` → `line_id`.

| Campo de `data.parts[0]` | Valor esperado |
|---|---|
| `status.value` | `reserved` |
| `is_budgeted` | `true` |
| `quantity` | `"2.000"` |
| `unit_price` | `"150.00"` |
| `work_order_item_id` | ID del ítem de la OT de tipo `part` |

Verificar en `GET {{base_url}}/parts/{{part_id}}`: `stock.reserved` `"2.000"`, `stock.available` `"13.000"`, `stock.on_hand` `"15.000"`.

Caso de borde — ajuste por debajo de lo reservado:

`POST {{base_url}}/inventory/adjustments`

```json
{
  "part_id": {{part_id}},
  "counted_quantity": 1,
  "reason": "Conteo"
}
```

Esperado: `422 STOCK_BELOW_RESERVED`.

### 12.3 Listar repuestos de la OT

`GET {{base_url}}/work-orders/{{work_order2_id}}/parts`

Esperado: `200`, una línea.

### 12.4 Modificar cantidad (OT `pending`)

`PATCH {{base_url}}/work-orders/{{work_order2_id}}/parts/{{line_id}}`

```json
{ "quantity": 3 }
```

Esperado: `200`, `quantity` `"3.000"`; `stock.reserved` del repuesto pasa a `"3.000"`.

Volver a 2:

```json
{ "quantity": 2, "notes": "Ajuste de cantidad" }
```

Esperado: `200`; `stock.reserved` `"2.000"`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Más que el disponible | `{ "quantity": 1000 }` | 422 | `INSUFFICIENT_STOCK` |
| Cantidad cero | `{ "quantity": 0 }` | 422 | `VALIDATION_ERROR` |
| Más de 3 decimales | `{ "quantity": 1.2345 }` | 422 | `VALIDATION_ERROR` |

### 12.5 Reglas de estado antes de iniciar

Body vacío salvo indicación.

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Reservar línea ya reservada | `POST {{base_url}}/work-orders/{{work_order2_id}}/parts/{{line_id}}/reserve` | — | 422 | `WORK_ORDER_PART_INVALID_STATUS_TRANSITION` |
| Consumir con OT `pending` | `POST {{base_url}}/work-orders/{{work_order2_id}}/parts/{{line_id}}/consume` | `{ "quantity_used": 2 }` | 422 | `WORK_ORDER_NOT_IN_PROGRESS` |
| Devolver con OT `pending` | `POST {{base_url}}/work-orders/{{work_order2_id}}/parts/{{line_id}}/return` | `{ "quantity": 1, "reason": "Prueba" }` | 422 | `WORK_ORDER_NOT_IN_PROGRESS` |
| Línea inexistente / de otra OT | `POST {{base_url}}/work-orders/{{work_order2_id}}/parts/999999/cancel` | — | 404 | `RESOURCE_NOT_FOUND` |

### 12.6 Agregar repuesto extra (no presupuestado)

`POST {{base_url}}/work-orders/{{work_order2_id}}/parts`

```json
{
  "part_id": {{part_unit_id}},
  "quantity": 1
}
```

Esperado: `201`, `work_order_item_id` `null`, `is_budgeted` `false`, `unit_price` `"50000.00"` (precio de catálogo), `status.value` `awaiting_approval` ("Pendiente de aprobación"), `client_decision` `null` → `extra_line_id`. No reserva stock (`stock.reserved` no cambia). Recepcionista y jefe de taller reciben el aviso `extra_part_awaiting_approval` en `GET {{base_url}}/me/notifications`.

Con precio informado:

```json
{
  "part_id": {{part_unit_id}},
  "quantity": 1,
  "unit_price": 45000,
  "notes": "Precio acordado con el cliente"
}
```

Esperado: `201`, `unit_price` `"45000.00"`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Repuesto inactivo | `{ "part_id": <repuesto inactivo>, "quantity": 1 }` | 422 | `PART_NOT_USABLE` |
| Fracción en repuesto por unidad | `{ "part_id": {{part_unit_id}}, "quantity": 1.5 }` | 422 | `INVENTORY_FRACTIONAL_QUANTITY_NOT_ALLOWED` |
| Repuesto inexistente | `{ "part_id": 999999, "quantity": 1 }` | 422 | `VALIDATION_ERROR` |
| Sin cantidad | `{ "part_id": {{part_unit_id}} }` | 422 | `VALIDATION_ERROR` |
| Precio negativo | `{ "part_id": {{part_unit_id}}, "quantity": 1, "unit_price": -5 }` | 422 | `VALIDATION_ERROR` |

### 12.6.1 Respuesta del cliente al extra

Mientras espera la respuesta, el extra no se reserva ni se consume. Aprobar (permiso `work-order-parts.approve`:
administrador, recepcionista y jefe de taller):

`POST {{base_url}}/work-orders/{{work_order2_id}}/parts/{{extra_line_id}}/approve`

```json
{
  "channel": "phone",
  "contact": "Titular",
  "notes": "Aceptó por teléfono"
}
```

Esperado: `200`, `status.value` `reserved` (o `pending` si no hay stock), `client_decision.value` `approved`,
`client_decision.channel.value` `phone`, `decided_by` = usuario del token, `decided_at` con la fecha.

`channel`: `phone | whatsapp | in_person | email | other`. Para rechazar, `POST .../parts/{id}/reject` con el mismo body
y `notes` obligatorio (motivo): la línea queda `cancelled` con `client_decision.value` `rejected`.

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Reservar o consumir sin respuesta | `POST .../parts/{id}/reserve` (o `consume`) sobre un extra `awaiting_approval` | — | 422 | `WORK_ORDER_PART_NOT_APPROVED` |
| Sin medio | `POST .../parts/{id}/approve` | `{}` | 422 | `VALIDATION_ERROR` (`errors.channel`) |
| Rechazar sin motivo | `POST .../parts/{id}/reject` | `{ "channel": "phone" }` | 422 | `VALIDATION_ERROR` (`errors.notes`) |
| Aprobar de nuevo / línea presupuestada | `POST .../parts/{{extra_line_id}}/approve` o sobre `{{line_id}}` | `{ "channel": "phone" }` | 422 | `WORK_ORDER_PART_INVALID_STATUS_TRANSITION` |
| Cambiar cantidad o precio aprobados | `PATCH .../parts/{{extra_line_id}}` | `{ "quantity": 2 }` | 422 | `WORK_ORDER_PART_APPROVED_NOT_EDITABLE` |
| Aprobar con token de depósito o mecánico | `POST .../parts/{{extra_line_id}}/approve` | `{ "channel": "phone" }` | 403 | `FORBIDDEN` |

Antes de la respuesta, cantidad y precio se corrigen con `PATCH`. Aprobados, solo cambian las notas (reenviar los
mismos valores es válido); para otro número se cancela el extra y se carga uno nuevo.

Si en 12.6 se creó la línea con precio informado, aprobarla o rechazarla también: un extra sin respuesta impide
completar la OT (12.12).

### 12.7 Línea sin stock (pendiente) y reserva manual

`POST {{base_url}}/work-orders/{{work_order2_id}}/parts`

```json
{
  "part_id": {{part_unit_id}},
  "quantity": 10
}
```

Esperado: `201`, `status.value` `awaiting_approval` → `pending_line_id`.

| Paso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Aprobar | `POST {{base_url}}/work-orders/{{work_order2_id}}/parts/{{pending_line_id}}/approve` | `{ "channel": "in_person" }` | 200 | `pending` (no hay 10 unidades disponibles) |
| Reservar sin stock | `POST {{base_url}}/work-orders/{{work_order2_id}}/parts/{{pending_line_id}}/reserve` | — | 422 | `INSUFFICIENT_STOCK` |
| Bajar cantidad a 1 | `PATCH {{base_url}}/work-orders/{{work_order2_id}}/parts/{{pending_line_id}}` | `{ "quantity": 1 }` | 422 | `WORK_ORDER_PART_APPROVED_NOT_EDITABLE` (lo aprobado no cambia) |
| Cancelar línea | `POST {{base_url}}/work-orders/{{work_order2_id}}/parts/{{pending_line_id}}/cancel` | — | 200 | `cancelled` (no tenía reserva) |
| Cancelar de nuevo | `POST {{base_url}}/work-orders/{{work_order2_id}}/parts/{{pending_line_id}}/cancel` | — | 422 | `WORK_ORDER_PART_INVALID_STATUS_TRANSITION` |

### 12.8 Iniciar la OT

`POST {{base_url}}/work-orders/{{work_order2_id}}/start` → `200`, `in_progress`.

### 12.9 Consumir (token = `{{token_mec}}`)

`POST {{base_url}}/work-orders/{{work_order2_id}}/parts/{{line_id}}/consume`

```json
{
  "quantity_used": 1.7,
  "notes": "Se uso menos de lo presupuestado"
}
```

Esperado: `200`.

| Campo | Valor esperado |
|---|---|
| `status.value` | `consumed` |
| `quantity_consumed` | `"1.700"` |
| `quantity_net` | `"1.700"` |
| `unit_cost` | `"110.0000"` |
| `total_cost` | `"187.00"` |
| `total_price` | `"255.00"` |

Verificar:

- `GET {{base_url}}/parts/{{part_id}}` → `stock.on_hand` `"13.300"`, `stock.reserved` `"0.000"`.
- `GET {{base_url}}/parts/{{part_id}}/movements` → el más reciente es `work_order_out`, `quantity` `"-1.700"`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Consumir de nuevo | `{ "quantity_used": 1 }` | 422 | `WORK_ORDER_PART_INVALID_STATUS_TRANSITION` |
| Sin cantidad | `{}` | 422 | `VALIDATION_ERROR` |
| Cantidad cero | `{ "quantity_used": 0 }` | 422 | `VALIDATION_ERROR` |

Consumo mayor a lo aprobado (sobre `{{extra_line_id}}`, aprobada y reservada con 1 unidad):

```json
{ "quantity_used": 2 }
```

Esperado: `422 WORK_ORDER_PART_EXCEEDS_APPROVED`; la reserva y el stock no cambian. Una línea presupuestada sí puede
usar más de lo reservado (el excedente sale del disponible; sin disponible, `INSUFFICIENT_STOCK`).

### 12.10 Devolver (token = `{{token_mec}}`)

`POST {{base_url}}/work-orders/{{work_order2_id}}/parts/{{line_id}}/return`

```json
{
  "quantity": 0.2,
  "reason": "Sobrante"
}
```

Esperado: `200`, `status.value` `consumed`, `quantity_returned` `"0.200"`, `quantity_net` `"1.500"`, `total_cost` `"165.00"`, `total_price` `"225.00"`. El movimiento `work_order_return` usa `unit_cost` `"110.0000"` (costo de la línea).

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Devolver más que el neto | `{ "quantity": 5, "reason": "Sobrante" }` | 422 | `WORK_ORDER_PART_RETURN_EXCEEDS_CONSUMED` |
| Sin motivo | `{ "quantity": 0.1 }` | 422 | `VALIDATION_ERROR` (`errors.reason`) |
| Devolver una línea no consumida | sobre `{{extra_line_id}}`: `{ "quantity": 1, "reason": "x" }` | 422 | `WORK_ORDER_PART_INVALID_STATUS_TRANSITION` |

### 12.11 Permisos del mecánico (token = `{{token_mec}}`)

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Ver repuestos de la OT | `GET {{base_url}}/work-orders/{{work_order2_id}}/parts` | — | 200 | — |
| Agregar repuesto | `POST {{base_url}}/work-orders/{{work_order2_id}}/parts` | `{ "part_id": {{part_unit_id}}, "quantity": 1 }` | 403 | `FORBIDDEN` |
| Modificar línea | `PATCH {{base_url}}/work-orders/{{work_order2_id}}/parts/{{extra_line_id}}` | `{ "quantity": 2 }` | 403 | `FORBIDDEN` |
| Reservar | `POST .../parts/{{extra_line_id}}/reserve` | — | 403 | `FORBIDDEN` |
| Cancelar | `POST .../parts/{{extra_line_id}}/cancel` | — | 403 | `FORBIDDEN` |
| Aprobar o rechazar un extra | `POST .../parts/{{extra_line_id}}/approve` | `{ "channel": "phone" }` | 403 | `FORBIDDEN` |

### 12.12 Completar la OT con repuestos sin resolver

Token: administrador. Iniciar y completar cada ítem de la OT (`.../items/{id}/start` y `.../items/{id}/complete`).

| Paso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Completar con `{{extra_line_id}}` reservada | `POST {{base_url}}/work-orders/{{work_order2_id}}/complete` | — | 422 | `WORK_ORDER_HAS_UNRESOLVED_PARTS` |
| Cancelar la línea extra | `POST {{base_url}}/work-orders/{{work_order2_id}}/parts/{{extra_line_id}}/cancel` | — | 200 | `cancelled` |
| Completar | `POST {{base_url}}/work-orders/{{work_order2_id}}/complete` | — | 200 | `completed` |

> Si en 12.6 se creó también la línea con precio informado, cancelarla, rechazarla o consumirla antes de completar:
> un extra `awaiting_approval` también da `WORK_ORDER_HAS_UNRESOLVED_PARTS`.

Verificar en la respuesta de completar: `parts_summary.total_cost` `"165.00"`, `parts_summary.total_price` `"225.00"`, `parts_summary.unresolved_count` `0`.

### 12.13 OT completada: repuestos congelados

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Agregar repuesto | `POST {{base_url}}/work-orders/{{work_order2_id}}/parts` | `{ "part_id": {{part_id}}, "quantity": 1 }` | 422 | `WORK_ORDER_PARTS_NOT_EDITABLE` |
| Modificar línea | `PATCH {{base_url}}/work-orders/{{work_order2_id}}/parts/{{line_id}}` | `{ "notes": "x" }` | 422 | `WORK_ORDER_PARTS_NOT_EDITABLE` |
| Devolver | `POST .../parts/{{line_id}}/return` | `{ "quantity": 0.1, "reason": "x" }` | 422 | `WORK_ORDER_NOT_IN_PROGRESS` |

### 12.14 Cancelación de la OT libera reservas

1. Crear presupuesto nuevo con un ítem `part` de `{{part_id}}` (cantidad 3), enviar y aprobar (como 12.1).
2. Crear la OT (como 12.2) → `work_order3_id`. Verificar `stock.reserved` `"3.000"`.
3. `POST {{base_url}}/work-orders/{{work_order3_id}}/cancel` → `200`, `parts[0].status.value` `cancelled`.
4. Verificar `stock.reserved` `"0.000"` y `stock.on_hand` sin cambios.

### 12.15 Cancelación de un ítem libera su repuesto

1. Repetir 12.14 pasos 1–2 → `work_order4_id`.
2. `POST {{base_url}}/work-orders/{{work_order4_id}}/start` → `200`.
3. `POST {{base_url}}/work-orders/{{work_order4_id}}/items/{id del ítem part}/cancel` → `200`.
4. `GET {{base_url}}/work-orders/{{work_order4_id}}/parts` → la línea vinculada está `cancelled`; `stock.reserved` vuelve al valor previo.

### 12.16 OT sin stock al crearse

1. Presupuesto con ítem `part` de `{{part_unit_id}}`, cantidad mayor al disponible (por ejemplo 50), enviar y aprobar.
2. Crear la OT → `201` (se crea igual).
3. Esperado: `parts[0].status.value` `pending`, `parts_summary.unresolved_count` `1`.

### 12.17 Devolución de repuesto descontinuado

1. Sobre una OT en progreso con una línea consumida, descontinuar el repuesto: `POST {{base_url}}/parts/{id}/discontinue`.
2. `POST .../parts/{línea}/return` con `{ "quantity": 1, "reason": "Sobrante" }` → `200` (permitido).

---

## FASE 13 — Servicios y mano de obra (comisiones)

Token: administrador salvo indicación.

> Comisión de cada mecánico = **total del ítem (sin IVA) × % del trabajo × % de comisión del mecánico**. Solo los ítems `labor` y `service` generan comisión.
> Tiempo estimado, horas reales y costo del mecánico son **opcionales**: sin horas cargadas, el costo de mano de obra es `null` (desconocido), nunca cero.
> Costo por hora del seeder: `MEC-001` **45.000**, `MEC-002` **35.000**.

Mecánicos del seeder: `MEC-001` (usuario `jperez`, comisión **10%**) y `MEC-002` (usuario `cgomez`, comisión **8.5%**). En `GET {{base_url}}/mechanics` guardar sus IDs en `mec1_id` y `mec2_id`.

### 13.1 Categorías de servicio

`GET {{base_url}}/service-categories`

Esperado: `200`, lista con `id`, `code`, `name`, `services_count` (General, Mecánica, Chapería, Electricidad, Neumáticos). Guardar el ID de "Mecánica" → `service_category_id`.

### 13.2 Crear servicio

`POST {{base_url}}/services`

```json
{
  "service_category_id": {{service_category_id}},
  "name": "Alineacion QA",
  "description": "Incluye 4 ruedas (hasta R17).",
  "standard_price": 120000,
  "estimated_hours": 1.5
}
```

Esperado: `201`, `code` `SRV-XXXXXX`, `status.value` `active`, `standard_price` `"120000.00"`, `estimated_hours` `"1.50"` → `service_id`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Body vacío | `{}` | 422 | `VALIDATION_ERROR` (`service_category_id`, `name`, `standard_price`) |
| Nombre con carácter no permitido | `{ "service_category_id": {{service_category_id}}, "name": "Servicio @promo", "standard_price": 1 }` | 422 | `VALIDATION_ERROR` (`errors.name`) |
| Precio negativo | `{ "service_category_id": {{service_category_id}}, "name": "Servicio QA", "standard_price": -1 }` | 422 | `VALIDATION_ERROR` |
| Horas estimadas negativas | `{ "service_category_id": {{service_category_id}}, "name": "Servicio QA", "standard_price": 1, "estimated_hours": -1 }` | 422 | `VALIDATION_ERROR` (`errors.estimated_hours`) |
| Categoría inexistente | `{ "service_category_id": 999999, "name": "Servicio QA", "standard_price": 1 }` | 422 | `VALIDATION_ERROR` |

Crear un segundo servicio descartable (mismo body con `"name": "Servicio Descartable QA"`) → `service_unused_id`.

### 13.3 Consultar, editar y cambiar estado

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Listar | `GET {{base_url}}/services?search=QA` | — | 200 | Servicios QA con `meta.pagination` |
| Filtrar por categoría | `GET {{base_url}}/services?category_id={{service_category_id}}` | — | 200 | Filtrado |
| Detalle | `GET {{base_url}}/services/{{service_id}}` | — | 200 | Incluye `description` y `category` |
| Editar (PATCH parcial) | `PATCH {{base_url}}/services/{{service_id}}` | `{ "standard_price": 125000 }` | 200 | `standard_price` `"125000.00"`, el resto sin cambios |
| Volver al precio original | `PATCH {{base_url}}/services/{{service_id}}` | `{ "standard_price": 120000 }` | 200 | — |
| Activar uno activo | `POST {{base_url}}/services/{{service_unused_id}}/activate` | — | 422 | `SERVICE_INVALID_STATUS_TRANSITION` |
| Desactivar | `POST {{base_url}}/services/{{service_unused_id}}/deactivate` | — | 200 | `inactive` |
| Eliminar sin uso | `DELETE {{base_url}}/services/{{service_unused_id}}` | — | 200 | — |
| Inexistente | `GET {{base_url}}/services/999999` | — | 404 | `RESOURCE_NOT_FOUND` |

### 13.4 Permisos del mecánico sobre el catálogo (token = `{{token_mec}}`)

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Ver categorías | `GET {{base_url}}/service-categories` | — | 200 | — |
| Ver servicios | `GET {{base_url}}/services` | — | 200 | — |
| Crear | `POST {{base_url}}/services` | body de 13.2 | 403 | `FORBIDDEN` |
| Editar | `PATCH {{base_url}}/services/{{service_id}}` | `{ "standard_price": 1 }` | 403 | `FORBIDDEN` |
| Desactivar | `POST {{base_url}}/services/{{service_id}}/deactivate` | — | 403 | `FORBIDDEN` |

### 13.5 Presupuesto con servicio del catálogo

1. `POST {{base_url}}/budgets` con `{ "reception_id": {{reception_id}} }` → `budget5_id`.
2. Servicio **sin precio** (toma el de lista):

`POST {{base_url}}/budgets/{{budget5_id}}/items`

```json
{
  "type": "service",
  "service_id": {{service_id}},
  "description": "Alineacion y balanceo",
  "quantity": 1
}
```

Esperado: `201`, `service.code` informado, `unit_price` `"120000.00"`, `total` `"120000.00"`, `estimated_hours` `"1.50"` (catálogo × cantidad).

3. Mano de obra fuera de catálogo:

```json
{
  "type": "labor",
  "description": "Cambio de embrague",
  "quantity": 1,
  "unit_price": 200000,
  "estimated_hours": 3
}
```

Esperado: `201`, `estimated_hours` `"3.00"` (opcional en mano de obra).

Casos de error y borde (sobre `{{budget5_id}}` en `draft`):

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Servicio con precio propio (válido) | `{ "type": "service", "service_id": {{service_id}}, "description": "Con descuento", "quantity": 1, "unit_price": 100000 }` | 201 | — (`unit_price` `"100000.00"`) |
| `service_id` en ítem que no es servicio | `{ "type": "labor", "service_id": {{service_id}}, "description": "Mano de obra", "quantity": 1, "unit_price": 100 }` | 422 | `VALIDATION_ERROR` (`errors.service_id`) |
| Servicio sin catálogo ni precio | `{ "type": "service", "description": "Trabajo especial", "quantity": 1 }` | 422 | `VALIDATION_ERROR` (`errors.unit_price`) |
| Repuesto con horas estimadas | `{ "type": "part", "description": "Filtro", "quantity": 1, "unit_price": 1, "estimated_hours": 1 }` | 422 | `VALIDATION_ERROR` (`errors.estimated_hours`) |
| Servicio con horas propias (válido) | `{ "type": "service", "service_id": {{service_id}}, "description": "Compleja", "quantity": 1, "estimated_hours": 2.25 }` | 201 | — (`estimated_hours` `"2.25"`) |
| Servicio inactivo | crear y desactivar un servicio; `{ "type": "service", "service_id": <inactivo>, "description": "X", "quantity": 1 }` | 422 | `SERVICE_NOT_USABLE` |
| Eliminar un servicio usado | `DELETE {{base_url}}/services/{{service_id}}` | 409 | `SERVICE_IN_USE` (también si lo usa un plan de mantenimiento) |
| Renombrar un servicio usado | `PATCH {{base_url}}/services/{{service_id}}` con `{ "name": "Otro" }` | 422 | `SERVICE_NAME_NOT_EDITABLE` (precio, horas, descripción y categoría sí se editan) |
| Eliminar un mecánico con trabajos | `DELETE {{base_url}}/mechanics/{{mechanic_id}}` | 409 | `MECHANIC_IN_USE` (pasarlo a inactivo) |
| Eliminar un vehículo con recepciones | `DELETE {{base_url}}/vehicles/{{vehicle_id}}` | 409 | `VEHICLE_IN_USE` |
| Eliminar un ítem de checklist usado | `DELETE {{base_url}}/checklists/{id}` | 409 | `CHECKLIST_ITEM_IN_USE` (`PATCH` con `{ "is_active": false }` lo saca de los checklists nuevos; `GET checklists?is_active=0` lista los inactivos) |

> Eliminar los ítems "Con descuento" y "Compleja" si se crearon, para mantener los valores esperados (`DELETE {{base_url}}/budgets/{{budget5_id}}/items/{id}`). Al cambiar un ítem a `"type": "labor"` con `PATCH`, el `service_id` vuelve a `null`.

4. `POST {{base_url}}/budgets/{{budget5_id}}/send` → `200`; `POST .../approve` → `200`.

### 13.6 OT con asignación automática al responsable

`POST {{base_url}}/work-orders`

```json
{
  "budget_id": {{budget5_id}},
  "mechanic_id": {{mec1_id}}
}
```

Esperado: `201` → `work_order5_id`. Guardar el ID del ítem `labor` → `labor_item_id` y del ítem `service` → `service_item_id`.

| Verificación | Valor esperado |
|---|---|
| `items[labor].mechanics[0]` | `mechanic_id` = `{{mec1_id}}`, `share_percentage` `"100.00"`, `commission_percentage` `"10.00"`, `commission_amount` `"20000.00"` |
| `items[service].mechanics[0].commission_amount` | `"12000.00"` |
| `commission_summary.total_commission` | `"32000.00"` |
| `commission_summary.is_final` | `false` |
| `items[labor].estimated_hours` / `items[service].estimated_hours` | `"3.00"` / `"1.50"` |
| `labor_summary` | `estimated_hours` `"4.50"`, `actual_hours` `null`, `labor_cost` `null`, `assignments_without_hours` `2`, `is_complete` `false` |

Cargar horas con la OT `pending`: `PATCH {{base_url}}/work-orders/{{work_order5_id}}/items/{{labor_item_id}}/mechanics/{{mec1_id}}/hours` con `{ "hours": 1 }` → `422 WORK_ORDER_ITEM_HOURS_NOT_EDITABLE`.

### 13.7 Cambio de responsable (OT `pending`)

`PATCH {{base_url}}/work-orders/{{work_order5_id}}`

```json
{ "mechanic_id": {{mec2_id}} }
```

Esperado: `200`; los dos trabajos pasan a `{{mec2_id}}` con `commission_percentage` `"8.50"`.

Volver al responsable original: `{ "mechanic_id": {{mec1_id}} }` → `200`, trabajos otra vez en `{{mec1_id}}` con `"10.00"`.

### 13.8 Asignar varios mecánicos a un trabajo

Ver la asignación actual: `GET {{base_url}}/work-orders/{{work_order5_id}}/items/{{labor_item_id}}/mechanics` → `200`, un mecánico.

**Partes iguales** (sin porcentajes):

`PUT {{base_url}}/work-orders/{{work_order5_id}}/items/{{labor_item_id}}/mechanics`

```json
{
  "mechanics": [
    { "mechanic_id": {{mec1_id}} },
    { "mechanic_id": {{mec2_id}} }
  ]
}
```

Esperado: `200`; `{{mec1_id}}` 50% → `commission_amount` `"10000.00"`; `{{mec2_id}}` 50% → `"8500.00"`.

**Reparto personalizado:**

```json
{
  "mechanics": [
    { "mechanic_id": {{mec1_id}}, "share_percentage": 70 },
    { "mechanic_id": {{mec2_id}}, "share_percentage": 30 }
  ]
}
```

Esperado: `200`; `{{mec1_id}}` → `"14000.00"` (200.000 × 70% × 10%); `{{mec2_id}}` → `"5100.00"` (200.000 × 30% × 8.5%).
En `GET {{base_url}}/work-orders/{{work_order5_id}}`: `commission_summary.total_commission` `"31100.00"`.

Casos de error (la asignación 70/30 no debe cambiar):

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| No suman 100 | `{ "mechanics": [ { "mechanic_id": {{mec1_id}}, "share_percentage": 60 }, { "mechanic_id": {{mec2_id}}, "share_percentage": 30 } ] }` | 422 | `WORK_ORDER_ITEM_SHARES_INVALID` |
| Porcentaje solo en algunos | `{ "mechanics": [ { "mechanic_id": {{mec1_id}}, "share_percentage": 60 }, { "mechanic_id": {{mec2_id}} } ] }` | 422 | `WORK_ORDER_ITEM_SHARES_INVALID` |
| Mecánico repetido | `{ "mechanics": [ { "mechanic_id": {{mec1_id}} }, { "mechanic_id": {{mec1_id}} } ] }` | 422 | `VALIDATION_ERROR` |
| Lista vacía | `{ "mechanics": [] }` | 422 | `VALIDATION_ERROR` |
| Mecánico inexistente | `{ "mechanics": [ { "mechanic_id": 999999 } ] }` | 422 | `VALIDATION_ERROR` |
| Mecánico no activo | `PATCH {{base_url}}/mechanics/{{mec2_id}}` con `{ "status": "on_leave" }`, luego asignarlo | 422 | `MECHANIC_NOT_ACTIVE` (después volver `{ "status": "active" }`) |
| Ítem de tipo repuesto | `PUT {{base_url}}/work-orders/{{work_order4_id}}/items/{id del ítem part}/mechanics` (OT de 12.15, en progreso) con `{ "mechanics": [ { "mechanic_id": {{mec1_id}} } ] }` | 422 | `WORK_ORDER_ITEM_NOT_COMMISSIONABLE` |
| Ítem de otra OT | `PUT {{base_url}}/work-orders/{{work_order5_id}}/items/999999/mechanics` | 404 | `RESOURCE_NOT_FOUND` |

**Comisión copiada al asignar:** `PATCH {{base_url}}/mechanics/{{mec1_id}}` con `{ "commission_percentage": 50 }` → la asignación existente sigue mostrando `"10.00"`. Volver a `{ "commission_percentage": 10 }`.

### 13.9 Permisos del mecánico sobre las asignaciones (token = `{{token_mec}}`)

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Ver asignación | `GET {{base_url}}/work-orders/{{work_order5_id}}/items/{{labor_item_id}}/mechanics` | — | 200 | — |
| Cambiar asignación | `PUT .../items/{{labor_item_id}}/mechanics` | `{ "mechanics": [ { "mechanic_id": {{mec1_id}} } ] }` | 403 | `FORBIDDEN` |

### 13.10 Completar la OT y consultar comisiones

Token: administrador.

1. `POST {{base_url}}/work-orders/{{work_order5_id}}/start` → `200`.

**Horas reales (opcional)** — `PATCH {{base_url}}/work-orders/{{work_order5_id}}/items/{item}/mechanics/{mechanic}/hours`:

| Token | Ítem / mecánico | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| `{{token_mec}}` (MEC-001) | `{{labor_item_id}}` / `{{mec1_id}}` | `{ "hours": 2.5 }` | 200 | `hours` `"2.50"`, `hour_cost` `"45000.00"`, `labor_cost` `"112500.00"` |
| `{{token_mec}}` (MEC-001) | `{{labor_item_id}}` / `{{mec2_id}}` | `{ "hours": 1 }` | 403 | `FORBIDDEN` (solo carga las propias) |
| `{{token_admin}}` | `{{labor_item_id}}` / `{{mec2_id}}` | `{ "hours": 1 }` | 200 | `labor_cost` `"35000.00"` |
| `{{token_admin}}` | `{{service_item_id}}` / `{{mec1_id}}` | `{ "hours": 1 }` | 200 | `labor_cost` `"45000.00"` |
| `{{token_admin}}` | `{{service_item_id}}` / `{{mec2_id}}` (no asignado) | `{ "hours": 1 }` | 404 | `RESOURCE_NOT_FOUND` |
| `{{token_admin}}` | `{{labor_item_id}}` / `{{mec1_id}}` | `{ "hours": 0 }` | 422 | `VALIDATION_ERROR` |
| `{{token_admin}}` | `{{labor_item_id}}` / `{{mec1_id}}` | `{}` | 422 | `VALIDATION_ERROR` (`hours` es obligatorio; `null` lo borra) |

Verificar en `GET {{base_url}}/work-orders/{{work_order5_id}}`: `labor_summary` → `estimated_hours` `"4.50"`, `actual_hours` `"4.50"`, `labor_cost` `"192500.00"`, `assignments_without_hours` `0`, `is_complete` `true`.

> Borrar horas: el mismo `PATCH` con `{ "hours": null }` deja `hours` y `labor_cost` en `null`. Si se prueba, volver a cargarlas para mantener los valores esperados.

2. Iniciar y completar cada ítem (`.../items/{id}/start` y `.../items/{id}/complete`).
3. `POST {{base_url}}/work-orders/{{work_order5_id}}/complete` → `200`, `commission_summary.is_final` `true`, `total_commission` `"31100.00"`.

**Reporte de comisiones** (por defecto, el mes en curso):

| Caso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| MEC-001 | `GET {{base_url}}/mechanics/{{mec1_id}}/commissions` | 200 | `total_commission` `"26000.00"` (14.000 mano de obra + 12.000 servicio), 2 líneas con `work_order_code`, `item_total`, `share_percentage`, `commission_amount` |
| MEC-002 | `GET {{base_url}}/mechanics/{{mec2_id}}/commissions` | 200 | `total_commission` `"5100.00"`, 1 línea |
| Período sin OT | `GET {{base_url}}/mechanics/{{mec1_id}}/commissions?date_from=2020-01-01&date_to=2020-01-31` | 200 | `lines` vacío, `total_commission` `"0.00"` |
| Fecha mal formada | `GET {{base_url}}/mechanics/{{mec1_id}}/commissions?date_from=ayer` | 422 | `VALIDATION_ERROR` |
| Rango invertido | `GET {{base_url}}/mechanics/{{mec1_id}}/commissions?date_from=2026-02-01&date_to=2026-01-01` | 422 | `VALIDATION_ERROR` |
| Mecánico inexistente | `GET {{base_url}}/mechanics/999999/commissions` | 404 | `RESOURCE_NOT_FOUND` |

> Los totales esperados asumen que no hay otras OT completadas en el mes con trabajos de MEC-001 o MEC-002. Las OT de las fases anteriores (por ejemplo `{{work_order_id}}` y `{{work_order2_id}}`) quedaron asignadas a su responsable por la migración: si alguna es de MEC-001 o MEC-002, sus líneas también aparecen y suman al total.

**Mecánico consultando** (token = `{{token_mec}}`, usuario `jperez` = MEC-001):

| Caso | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| Sus comisiones | `GET {{base_url}}/mechanics/{{mec1_id}}/commissions` | 200 | — |
| Comisiones de otro | `GET {{base_url}}/mechanics/{{mec2_id}}/commissions` | 403 | `FORBIDDEN` |

### 13.11 OT completada: asignaciones y horas congeladas

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Cambiar asignación | `PUT {{base_url}}/work-orders/{{work_order5_id}}/items/{{labor_item_id}}/mechanics` | `{ "mechanics": [ { "mechanic_id": {{mec1_id}} } ] }` | 422 | `WORK_ORDER_ITEM_MECHANICS_NOT_EDITABLE` |
| Cargar horas | `PATCH {{base_url}}/work-orders/{{work_order5_id}}/items/{{labor_item_id}}/mechanics/{{mec1_id}}/hours` | `{ "hours": 5 }` | 422 | `WORK_ORDER_ITEM_HOURS_NOT_EDITABLE` |

### 13.12 Trabajo cancelado no genera comisión

1. Repetir 13.5 y 13.6 con un presupuesto nuevo → `work_order6_id`.
2. Iniciar la OT y cancelar el ítem `service`: `POST .../items/{id}/cancel` → `200`.
3. `PUT` de mecánicos sobre ese ítem → `422 WORK_ORDER_ITEM_MECHANICS_NOT_EDITABLE`.
4. Completar el ítem `labor` y la OT → `commission_summary.total_commission` `"20000.00"` (el servicio cancelado no suma).

---

## FASE 14 — Cierre económico de la OT

Token: administrador salvo indicación. Parte de las OT `{{work_order5_id}}` (13.10) y `{{work_order6_id}}` (13.12), ya completadas.

> Importes **sin IVA**. Al completar la OT se congelan venta, costos, margen y comisiones.
> **Venta** = mano de obra/servicios no cancelados (total presupuestado) + repuestos **usados** (cantidad neta × `unit_price`, incluidos los extras) + repuestos fuera de catálogo no cancelados.
> **Costo** = repuestos (neta × `unit_cost`) + mano de obra (horas × costo por hora) + comisiones.
> Si falta algún costo (horas sin cargar, repuesto fuera de catálogo), el margen se calcula con los costos conocidos y `is_cost_complete` es `false`.
> `economic_summary` solo aparece con el permiso `work-orders.view-economics` (el mecánico no lo ve).

### 14.1 Resumen provisional de una OT abierta

Repetir 13.5 y 13.6 con un presupuesto nuevo (servicio 120.000 + mano de obra 200.000, responsable `{{mec1_id}}`) → `work_order7_id`. No iniciarla.

`GET {{base_url}}/work-orders/{{work_order7_id}}`

| Campo de `data.economic_summary` | Valor esperado |
|---|---|
| `is_final` / `closed_at` | `false` / `null` |
| `budgeted` | `labor` `"320000.00"`, `parts` `"0.00"`, `total` `"320000.00"` |
| `sale.total` / `variance` | `"320000.00"` / `"0.00"` |
| `cost` | `parts` `"0.00"`, `labor` `null` (sin horas), `commissions` `"32000.00"`, `total` `"32000.00"` |
| `gross_margin` / `margin_percentage` | `"288000.00"` / `"90.00"` |
| `is_cost_complete` | `false` |

### 14.2 Cierre congelado de una OT completada

`GET {{base_url}}/work-orders/{{work_order5_id}}`

| Campo de `data.economic_summary` | Valor esperado |
|---|---|
| `is_final` / `closed_at` | `true` / fecha de cierre (= `completed_at`) |
| `budgeted.total` / `sale.total` / `variance` | `"320000.00"` / `"320000.00"` / `"0.00"` |
| `cost.labor` | `"192500.00"` (2,5 h × 45.000 + 1 h × 35.000 + 1 h × 45.000) |
| `cost.commissions` | `"31100.00"` (14.000 + 5.100 + 12.000) |
| `cost.total` | `"223600.00"` |
| `gross_margin` / `margin_percentage` | `"96400.00"` / `"30.13"` |
| `is_cost_complete` | `true` |

**Congelado:** `PATCH {{base_url}}/mechanics/{{mec1_id}}` con `{ "commission_percentage": 50, "hour_cost": 99999 }` → repetir el `GET`: el `economic_summary` no cambia. Volver a `{ "commission_percentage": 10, "hour_cost": 45000 }`.

### 14.3 Trabajo cancelado y sin horas

`GET {{base_url}}/work-orders/{{work_order6_id}}`

| Campo de `data.economic_summary` | Valor esperado |
|---|---|
| `budgeted.total` / `sale.total` | `"320000.00"` / `"200000.00"` (el servicio cancelado no se vende) |
| `variance` | `"-120000.00"` |
| `cost` | `labor` `null`, `commissions` `"20000.00"`, `total` `"20000.00"` |
| `gross_margin` / `margin_percentage` | `"180000.00"` / `"90.00"` |
| `is_cost_complete` | `false` (no se cargaron horas) |

### 14.4 Repuestos en el cierre (opcional)

Sobre `{{work_order2_id}}` (FASE 12, completada): `sale.parts` = suma de `parts[].total_price` (cantidad neta usada, incluido el extra) y `cost.parts` = suma de `parts[].total_cost`; ambos coinciden con `parts_summary.total_price` / `total_cost`. Un ítem `part` sin catálogo suma su total presupuestado a `sale.parts` y deja `is_cost_complete` en `false`.

### 14.5 Permisos del mecánico (token = `{{token_mec}}`)

| Caso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Ver la OT | `GET {{base_url}}/work-orders/{{work_order5_id}}` | 200 | Sin la clave `economic_summary` (sí `commission_summary` y `labor_summary`) |
| Liquidar comisiones | `POST {{base_url}}/mechanics/{{mec1_id}}/commissions/settle` (body de 14.7) | 403 | `FORBIDDEN` |

### 14.6 Reporte de comisiones con estado

El reporte lee las comisiones congeladas al completar cada OT. Nuevos campos: `total_pending`, `total_settled` y `lines[].settled_at`.

| Caso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| MEC-001 | `GET {{base_url}}/mechanics/{{mec1_id}}/commissions` | 200 | `total_commission` `"46000.00"` (26.000 de `{{work_order5_id}}` + 20.000 de `{{work_order6_id}}`), `total_pending` `"46000.00"`, `total_settled` `"0.00"`, 3 líneas con `settled_at` `null` |
| MEC-002 | `GET {{base_url}}/mechanics/{{mec2_id}}/commissions` | 200 | `total_commission` `"5100.00"`, pendiente |
| Solo pendientes | `GET {{base_url}}/mechanics/{{mec1_id}}/commissions?status=pending` | 200 | 3 líneas |
| Estado inválido | `GET {{base_url}}/mechanics/{{mec1_id}}/commissions?status=pagado` | 422 | `VALIDATION_ERROR` (`errors.status`) |

> `{{work_order7_id}}` no suma: está abierta. Como en 13.10, si OT anteriores de MEC-001 se completaron en el mes, también aparecen.

### 14.7 Liquidar comisiones

`POST {{base_url}}/mechanics/{{mec1_id}}/commissions/settle`

```json
{
  "date_from": "2026-10-01",
  "date_to": "2026-10-31"
}
```

(Usar el primer y el último día del mes en curso.)

Esperado: `200`, `settled_count` `3`, `settled_total` `"46000.00"`, `settled_at` informado.

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Liquidar otra vez | mismo request | mismo body | 422 | `MECHANIC_COMMISSIONS_NOTHING_TO_SETTLE` |
| Período sin comisiones | mismo endpoint | `{ "date_from": "2020-01-01", "date_to": "2020-01-31" }` | 422 | `MECHANIC_COMMISSIONS_NOTHING_TO_SETTLE` |
| Sin fechas | mismo endpoint | `{}` | 422 | `VALIDATION_ERROR` (`date_from`, `date_to`) |
| Rango invertido | mismo endpoint | `{ "date_from": "2026-10-31", "date_to": "2026-10-01" }` | 422 | `VALIDATION_ERROR` |
| Mecánico inexistente | `POST {{base_url}}/mechanics/999999/commissions/settle` | body válido | 404 | `RESOURCE_NOT_FOUND` |

Verificación posterior:

| Caso | Método / Endpoint | HTTP | Resultado |
|---|---|---|---|
| Liquidadas | `GET {{base_url}}/mechanics/{{mec1_id}}/commissions?status=settled` | 200 | 3 líneas con `settled_at`, `total_settled` `"46000.00"`, `total_pending` `"0.00"` |
| Pendientes | `GET {{base_url}}/mechanics/{{mec1_id}}/commissions?status=pending` | 200 | `lines` vacío |
| Otro mecánico | `GET {{base_url}}/mechanics/{{mec2_id}}/commissions` | 200 | `total_pending` `"5100.00"` (no se liquidó) |
| Mecánico consultando las suyas | `GET {{base_url}}/mechanics/{{mec1_id}}/commissions` con `{{token_mec}}` | 200 | Ve `settled_at` de sus líneas |

---

## FASE 15 — Facturación

Token: administrador salvo indicación.

> Precios **con IVA incluido**, importes en **guaraníes enteros**. Los precios del sistema son sin IVA: la factura los convierte (precio sin IVA × 1,10 al 10%).
> Liquidación: **IVA 10% = total gravado 10% ÷ 11**, **IVA 5% = total gravado 5% ÷ 21**.
> Numeración `001-001-0000001` (establecimiento − punto de expedición − correlativo), asignada **al emitir**.

### 15.1 Timbrado

`POST {{base_url}}/invoice-stamps`

```json
{
  "stamp_number": "12345678",
  "establishment": "001",
  "expedition_point": "001",
  "valid_from": "2026-10-01",
  "valid_until": "2027-09-30"
}
```

Esperado: `201`, `next_number` `1`, `next_invoice_number` `"001-001-0000001"`, `is_active` `true`, `is_valid_today` `true` → `stamp_id`.

> Si la base se sembró con `InvoiceStampSeeder`, el timbrado ya existe: `GET {{base_url}}/invoice-stamps` y guardar su `id`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Otro activo en el mismo establecimiento y punto | `{ "stamp_number": "87654321", "establishment": "001", "expedition_point": "001", "valid_from": "2026-10-01", "valid_until": "2027-09-30" }` | 409 | `INVOICE_STAMP_OVERLAP` |
| Número de timbrado inválido | `{ ..., "stamp_number": "1234" }` | 422 | `VALIDATION_ERROR` (`errors.stamp_number`) |
| Establecimiento inválido | `{ ..., "establishment": "1" }` | 422 | `VALIDATION_ERROR` (`errors.establishment`) |
| Vigencia invertida | `{ ..., "valid_from": "2027-01-01", "valid_until": "2026-01-01" }` | 422 | `VALIDATION_ERROR` (`errors.valid_until`) |
| Próximo número fuera de rango | `{ ..., "next_number": 10000000 }` | 422 | `VALIDATION_ERROR` (`errors.next_number`) |

Listar: `GET {{base_url}}/invoice-stamps` → `200`.

### 15.2 Generar la factura en borrador

`POST {{base_url}}/work-orders/{{work_order5_id}}/invoice` (sin body)

Esperado: `201` → `invoice_id`.

| Campo | Valor esperado |
|---|---|
| `status.value` / `number` | `draft` / `null` |
| `customer.name` / `customer.tax_id` | `"Lucia Benitez"` / `"QA-1001"` (copiados del cliente) |
| `condition.value` | `cash` |
| Línea del servicio | `base_unit_price` `"120000.00"`, `tax_rate.value` `10`, `unit_price` `"132000.00"`, `total` `"132000.00"` |
| Línea de mano de obra | `base_unit_price` `"200000.00"`, `unit_price` `"220000.00"`, `total` `"220000.00"` |
| `totals` | `taxed_10` `"352000.00"`, `iva_10` `"32000.00"`, `exempt` `"0.00"`, `total` `"352000.00"` |

Guardar los IDs de las líneas en `invoice_service_item_id` y `invoice_labor_item_id`.

`GET {{base_url}}/work-orders/{{work_order5_id}}` → `data.invoice` con `status` `draft` y `total` `"352000.00"`.

| Caso | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| Facturar de nuevo la misma OT | `POST {{base_url}}/work-orders/{{work_order5_id}}/invoice` | 409 | `WORK_ORDER_ALREADY_INVOICED` |
| OT no completada | `POST {{base_url}}/work-orders/{{work_order7_id}}/invoice` (14.1, abierta) | 422 | `WORK_ORDER_NOT_INVOICEABLE` |
| OT inexistente | `POST {{base_url}}/work-orders/999999/invoice` | 404 | `RESOURCE_NOT_FOUND` |

> En OT con repuestos de catálogo se factura la **cantidad neta usada** (por ejemplo 1,7 L), no la presupuestada; los repuestos fuera de catálogo se facturan por lo presupuestado.

### 15.3 Editar líneas (tasa y descuento)

`PATCH {{base_url}}/invoices/{{invoice_id}}/items/{{invoice_service_item_id}}`

```json
{ "tax_rate": 5 }
```

Esperado: `200`; servicio `unit_price` `"126000.00"`; `totals.taxed_5` `"126000.00"`, `iva_5` `"6000.00"`, `taxed_10` `"220000.00"`, `iva_10` `"20000.00"`, `total` `"346000.00"`.

`PATCH {{base_url}}/invoices/{{invoice_id}}/items/{{invoice_labor_item_id}}`

```json
{ "discount": 22000, "description": "Cambio de embrague completo" }
```

Esperado: `200`; mano de obra `total` `"198000.00"`; `totals.iva_10` `"18000.00"`, `discount` `"22000.00"`, `total` `"324000.00"`.

| Caso | Línea / Body | HTTP | error_code |
|---|---|---|---|
| Exenta | servicio: `{ "tax_rate": 0 }` | 200 | `unit_price` `"120000.00"` (vuelve al precio sin IVA), `totals.exempt` `"120000.00"` |
| Tasa inválida | `{ "tax_rate": 7 }` | 422 | `VALIDATION_ERROR` (`errors.tax_rate`) |
| Descuento mayor al importe | mano de obra: `{ "discount": 300000 }` | 422 | `INVOICE_DISCOUNT_EXCEEDS_AMOUNT` |
| Descuento negativo | `{ "discount": -1 }` | 422 | `VALIDATION_ERROR` |

**Volver a los valores originales** para los pasos siguientes: servicio `{ "tax_rate": 10 }` y mano de obra `{ "discount": 0 }` → `totals.total` `"352000.00"`.

### 15.4 Editar la cabecera

`PATCH {{base_url}}/invoices/{{invoice_id}}`

```json
{
  "customer_name": "Transportes del Sur S.A.",
  "customer_tax_id": "80012345-6",
  "customer_address": "Av. Espana 1234"
}
```

Esperado: `200`, `customer` actualizado.

| Caso | Body | HTTP | Resultado / error_code |
|---|---|---|---|
| Crédito con vencimiento | `{ "condition": "credit", "due_date": "<hoy + 30 días>" }` | 200 | `condition.value` `credit` |
| Volver a contado | `{ "condition": "cash" }` | 200 | `due_date` `null` (se descarta) |
| Condición inválida | `{ "condition": "otro" }` | 422 | `VALIDATION_ERROR` |
| RUC con caracteres inválidos | `{ "customer_tax_id": "RUC 800!" }` | 422 | `VALIDATION_ERROR` |

### 15.5 Emitir

`POST {{base_url}}/invoices/{{invoice_id}}/issue` (body opcional: `{ "invoice_stamp_id": {{stamp_id}} }`)

Esperado: `200`, `status.value` `issued`, `number` `"001-001-0000001"`, `stamp.stamp_number` `"12345678"`, `issue_date` = hoy, `issued_at` informado.
En `GET {{base_url}}/invoice-stamps`: `next_number` `2`.

Casos de error (usar `{{invoice2_id}}`, generado desde `{{work_order6_id}}`: `POST {{base_url}}/work-orders/{{work_order6_id}}/invoice`):

| Caso | Preparación | HTTP | error_code |
|---|---|---|---|
| Sin RUC/CI | `PATCH .../invoices/{{invoice2_id}}` con `{ "customer_tax_id": null }` | 422 | `INVOICE_CUSTOMER_TAX_ID_REQUIRED` |
| Crédito sin vencimiento | `{ "customer_tax_id": "QA-1001", "condition": "credit" }` | 422 | `INVOICE_DUE_DATE_REQUIRED` |
| Crédito vencido | `{ "due_date": "<ayer>" }` | 422 | `INVOICE_DUE_DATE_REQUIRED` |
| Timbrado desactivado | Primero `{ "due_date": "<hoy + 30 días>" }` (el vencimiento se valida antes que el timbrado); luego `PATCH {{base_url}}/invoice-stamps/{{stamp_id}}` con `{ "is_active": false }` | 422 | `INVOICE_STAMP_NOT_AVAILABLE` (después volver `{ "is_active": true }`) |
| Timbrado inexistente | body `{ "invoice_stamp_id": 999999 }` | 422 | `VALIDATION_ERROR` |
| Emitir una factura ya emitida | `POST .../invoices/{{invoice_id}}/issue` | 422 | `INVOICE_NOT_ISSUABLE` |

Emisión a crédito válida: con el timbrado activo y `due_date` futura, emitir `{{invoice2_id}}` → `200`, `number` `"001-001-0000002"`, `condition.value` `credit`.

### 15.6 Factura emitida: inmutable

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Editar cabecera | `PATCH {{base_url}}/invoices/{{invoice_id}}` | `{ "customer_name": "Otro" }` | 422 | `INVOICE_NOT_EDITABLE` |
| Editar línea | `PATCH {{base_url}}/invoices/{{invoice_id}}/items/{{invoice_labor_item_id}}` | `{ "discount": 1 }` | 422 | `INVOICE_NOT_EDITABLE` |
| Eliminar | `DELETE {{base_url}}/invoices/{{invoice_id}}` | — | 422 | `INVOICE_NOT_EDITABLE` |

### 15.7 Anular y refacturar

`POST {{base_url}}/invoices/{{invoice2_id}}/cancel`

```json
{ "reason": "RUC del cliente incorrecto" }
```

Esperado: `200`, `status.value` `cancelled`, `number` `"001-001-0000002"` (se conserva), `cancellation_reason` informado.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Sin motivo | `{}` | 422 | `VALIDATION_ERROR` (`errors.reason`) |
| Anular otra vez | `{ "reason": "x" }` | 422 | `INVOICE_NOT_CANCELLABLE` |
| Anular un borrador | sobre un borrador | 422 | `INVOICE_NOT_CANCELLABLE` |

Después de anular:
1. `GET {{base_url}}/work-orders/{{work_order6_id}}` → `data.invoice` `null`.
2. `POST {{base_url}}/work-orders/{{work_order6_id}}/invoice` → `201` (se puede refacturar) → `invoice3_id`.
3. Emitir `{{invoice3_id}}` → `number` `"001-001-0000003"` (el número anulado no se reutiliza).

### 15.8 Descartar un borrador

Con un borrador (por ejemplo, generar uno nuevo para otra OT completada): `DELETE {{base_url}}/invoices/{id}` → `200`. No consume número y la OT queda libre para facturar.

### 15.9 Listado

| Caso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Todas | `GET {{base_url}}/invoices` | 200 | Con `meta.pagination` |
| Emitidas | `GET {{base_url}}/invoices?status=issued` | 200 | Solo emitidas |
| Por número o cliente | `GET {{base_url}}/invoices?search=001-001-0000001` | 200 | Filtrado |
| Por fecha de emisión | `GET {{base_url}}/invoices?date_from=<hoy>&date_to=<hoy>` | 200 | Emitidas hoy |
| Estado inválido | `GET {{base_url}}/invoices?status=pagada` | 422 | `VALIDATION_ERROR` |
| Detalle | `GET {{base_url}}/invoices/{{invoice_id}}` | 200 | `items`, `totals`, `customer`, `stamp` |

### 15.10 Permisos del mecánico (token = `{{token_mec}}`)

| Caso | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| Listar facturas | `GET {{base_url}}/invoices` | 403 | `FORBIDDEN` |
| Generar | `POST {{base_url}}/work-orders/{{work_order5_id}}/invoice` | 403 | `FORBIDDEN` |
| Timbrados | `GET {{base_url}}/invoice-stamps` | 403 | `FORBIDDEN` |
| Ver la OT | `GET {{base_url}}/work-orders/{{work_order5_id}}` | 200 | Sin la clave `invoice` |

---

## FASE 16 — Pagos

Token: administrador salvo indicación.

> Se paga **solo una factura emitida**, en uno o varios pagos (contado o crédito), hasta cubrir su total. Montos en **guaraníes enteros**.
> Medios: `cash` (efectivo), `card` (tarjeta), `transfer` (transferencia), `check` (cheque). Tarjeta, transferencia y cheque exigen `reference`; el cheque también `bank`.
> Los pagos de facturas **a crédito** llevan recibo numerado (`REC-000001`…). Un pago no se edita: **se anula** (con motivo) y el saldo vuelve a estar disponible.
> La factura muestra `payment_summary`: `paid_total`, `balance` y `status` (`unpaid`, `partial`, `paid`).
> **Vuelto** (solo efectivo): enviar `amount_received` (lo entregado por el cliente); se guarda `change_amount`. Lo aplicado a la factura es siempre `amount`.

### 16.1 Pagos parciales al contado

Sobre `{{invoice_id}}` (15.5: contado, emitida, total `"352000.00"`).

Casos de error (antes de pagar):

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Body vacío | `{}` | 422 | `VALIDATION_ERROR` (`amount`, `method`) |
| Monto con decimales | `{ "amount": 1000.5, "method": "cash" }` | 422 | `VALIDATION_ERROR` (`errors.amount`) |
| Monto cero | `{ "amount": 0, "method": "cash" }` | 422 | `VALIDATION_ERROR` (`errors.amount`) |
| Medio inválido | `{ "amount": 1000, "method": "bitcoin" }` | 422 | `VALIDATION_ERROR` (`errors.method`) |
| Tarjeta sin referencia | `{ "amount": 1000, "method": "card" }` | 422 | `VALIDATION_ERROR` (`errors.reference`) |
| Cheque sin banco | `{ "amount": 1000, "method": "check", "reference": "0012345" }` | 422 | `VALIDATION_ERROR` (`errors.bank`) |
| Fecha futura | `{ "amount": 1000, "method": "cash", "payment_date": "<mañana>" }` | 422 | `VALIDATION_ERROR` (`errors.payment_date`) |
| Fecha anterior a la emisión | `{ "amount": 1000, "method": "cash", "payment_date": "<día anterior a issue_date>" }` | 422 | `PAYMENT_DATE_BEFORE_ISSUE` |
| Supera el saldo | `{ "amount": 352001, "method": "cash" }` | 422 | `PAYMENT_EXCEEDS_BALANCE` |
| Entregado menor al monto | `{ "amount": 1000, "method": "cash", "amount_received": 500 }` | 422 | `VALIDATION_ERROR` (`errors.amount_received`) |
| Entregado en otro medio | `{ "amount": 1000, "method": "card", "reference": "V-1", "amount_received": 2000 }` | 422 | `VALIDATION_ERROR` (`errors.amount_received`) |

**Primer pago (efectivo):**

`POST {{base_url}}/invoices/{{invoice_id}}/payments`

```json
{ "amount": 100000, "method": "cash" }
```

Esperado: `201` → `payment1_id`.

| Campo | Valor esperado |
|---|---|
| `status.value` / `method.value` | `registered` / `cash` |
| `payment_date` | hoy (si no se informa) |
| `receipt_number` | `null` (al contado la factura es el comprobante) |
| `amount_received` / `change_amount` | `null` / `null` (pago exacto, sin vuelto) |
| `invoice.paid_total` / `invoice.balance` | `"100000.00"` / `"252000.00"` |
| `invoice.payment_status` | `partial` |

**Segundo pago (tarjeta, el resto):**

```json
{ "amount": 252000, "method": "card", "reference": "VOUCHER-123" }
```

Esperado: `201`, `reference` `"VOUCHER-123"`, `invoice.balance` `"0.00"`, `invoice.payment_status` `paid` → `payment2_id`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Pagar una factura ya pagada | `{ "amount": 1, "method": "cash" }` | 422 | `INVOICE_ALREADY_PAID` |

Verificar `GET {{base_url}}/invoices/{{invoice_id}}`: `payment_summary` → `paid_total` `"352000.00"`, `balance` `"0.00"`, `status` `paid`; `payments` con 2 pagos.

> En efectivo la `reference` se descarta (queda `null`) aunque se envíe.

### 16.2 Facturas que no admiten pagos

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Factura anulada | `POST {{base_url}}/invoices/{{invoice2_id}}/payments` (15.7) | `{ "amount": 1000, "method": "cash" }` | 422 | `INVOICE_NOT_PAYABLE` |
| Factura en borrador | `POST {{base_url}}/invoices/{id de un borrador}/payments` | `{ "amount": 1000, "method": "cash" }` | 422 | `INVOICE_NOT_PAYABLE` |
| Factura inexistente | `POST {{base_url}}/invoices/999999/payments` | `{ "amount": 1000, "method": "cash" }` | 404 | `RESOURCE_NOT_FOUND` |

### 16.3 Factura a crédito con recibos

1. `POST {{base_url}}/work-orders/{{work_order_id}}/invoice` (OT de la fase base: mano de obra 500) → `201` → `invoice4_id`, `totals.total` `"550.00"`.
2. `PATCH {{base_url}}/invoices/{{invoice4_id}}` con `{ "condition": "credit", "due_date": "<hoy + 30 días>" }` → `200`.
3. `POST {{base_url}}/invoices/{{invoice4_id}}/issue` → `200`, `number` `"001-001-0000004"`, `payment_summary.status` `unpaid`.

`POST {{base_url}}/invoices/{{invoice4_id}}/payments`

```json
{ "amount": 200, "method": "cash", "amount_received": 500 }
```

Esperado: `201`, `receipt_number` `"REC-000001"`, `amount` `"200.00"`, `amount_received` `"500.00"`, `change_amount` `"300.00"` (vuelto), `invoice.balance` `"350.00"`, `invoice.payment_status` `partial` → `payment3_id`.

```json
{ "amount": 350, "method": "transfer", "reference": "OP-998", "bank": "Banco Continental" }
```

Esperado: `201`, `receipt_number` `"REC-000002"`, `bank` `"Banco Continental"`, `invoice.payment_status` `paid` → `payment4_id`.

### 16.4 Anular un pago

`POST {{base_url}}/payments/{{payment4_id}}/cancel`

```json
{ "reason": "Transferencia rechazada" }
```

Esperado: `200`, `status.value` `cancelled`, `cancellation_reason` informado, `receipt_number` `"REC-000002"` (se conserva), `invoice.balance` `"350.00"`, `invoice.payment_status` `partial`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Sin motivo | `{}` | 422 | `VALIDATION_ERROR` (`errors.reason`) |
| Anular otra vez | `{ "reason": "x" }` | 422 | `PAYMENT_NOT_CANCELLABLE` |
| Pago inexistente | `POST {{base_url}}/payments/999999/cancel` con `{ "reason": "x" }` | 404 | `RESOURCE_NOT_FOUND` |

Volver a pagar el saldo: `POST {{base_url}}/invoices/{{invoice4_id}}/payments` con `{ "amount": 350, "method": "cash" }` → `201`, `receipt_number` `"REC-000003"` (el recibo anulado no se reutiliza), `invoice.payment_status` `paid`.

### 16.5 Anular una factura con pagos

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Factura con pagos vigentes | `POST {{base_url}}/invoices/{{invoice_id}}/cancel` | `{ "reason": "Error" }` | 422 | `INVOICE_HAS_PAYMENTS` |

> Para anularla primero hay que anular sus pagos (16.4) y después la factura (15.7).

### 16.5.1 Notas de crédito emitidas fuera del sistema (P10)

Ver `CLAUDE.md` 6.28. Sobre una factura **emitida** (`{{invoice_id}}`), con el token del cajero. Importes con IVA, en
guaraníes enteros.

`POST {{base_url}}/invoices/{{invoice_id}}/credit-notes`

```json
{
  "number": "001-002-0000123",
  "stamp_number": "12345678",
  "issue_date": "2026-10-29",
  "reason": "El cliente reclamó la mano de obra.",
  "items": [{ "invoice_item_id": {{invoice_item_id}}, "amount": 110000 }]
}
```

Esperado: `201`, `status.value` `registered`, `items[0].base_amount` proporcional a la línea →
`credit_note_id`. `GET invoices/{{invoice_id}}` trae `payment_summary.credited_total` y `credit_notes[]`, y el saldo
baja.

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Factura en borrador o anulada | `POST …/invoices/{id}/credit-notes` | el de arriba | 422 | `INVOICE_NOT_CREDITABLE` |
| Más de lo que le queda a la línea | ídem | `amount` mayor | 422 | `CREDIT_NOTE_EXCEEDS_LINE` |
| Línea de otra factura | ídem | `invoice_item_id` ajeno | 422 | `CREDIT_NOTE_ITEM_MISMATCH` |
| Más que el saldo (factura con pagos) | ídem | — | 422 | `CREDIT_NOTE_EXCEEDS_BALANCE` (anular antes el pago) |
| Fecha anterior a la factura / futura | ídem | `issue_date` | 422 | `CREDIT_NOTE_DATE_BEFORE_INVOICE` / `VALIDATION_ERROR` |
| Número repetido entre las vigentes | ídem | mismo `number` | 409 | `CREDIT_NOTE_DUPLICATE_NUMBER` |
| Importe con decimales | ídem | `"amount": 10.5` | 422 | `VALIDATION_ERROR` |
| Saldada solo con notas, sin pagos | `GET {{base_url}}/invoices/{{invoice_id}}` | — | 200 | `payment_summary.status` `credited`; con pagos y notas, `paid` |
| Anular la factura con notas vigentes | `POST …/invoices/{{invoice_id}}/cancel` | `{ "reason": "…" }` | 422 | `INVOICE_HAS_CREDIT_NOTES` |
| Anular la nota (mal cargada) | `POST …/invoices/{{invoice_id}}/credit-notes/{{credit_note_id}}/cancel` | `{ "reason": "Se cargó dos veces." }` | 200 | `status` `cancelled`; el saldo vuelve. Otra vez → `CREDIT_NOTE_NOT_CANCELLABLE` |
| Listar y ver | `GET …/credit-notes`, `GET …/credit-notes/{{credit_note_id}}` | — | 200 | Con otra factura en la URL → 404 |
| PDF de la factura | `GET {{base_url}}/invoices/{{invoice_id}}/pdf` | — | 200 | Lista las notas registradas y el saldo |
| Reporte de ventas | `GET {{base_url}}/reports/sales` | — | 200 | `credit_notes` (cantidad, total e IVA, por fecha de la nota) y `net_total`; `totals.total` sin cambios |
| Sin permiso | recepcionista: registrar o anular; mecánico: listar | — | 403 | `FORBIDDEN` |

### 16.6 Listado y resumen de caja

| Caso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Todos | `GET {{base_url}}/payments` | 200 | 5 pagos con `meta.pagination`; cada uno con `invoice_number` y `customer_name` |
| Por factura | `GET {{base_url}}/payments?invoice_id={{invoice4_id}}` | 200 | 3 pagos |
| Por medio | `GET {{base_url}}/payments?method=card` | 200 | 1 pago |
| Anulados | `GET {{base_url}}/payments?status=cancelled` | 200 | 1 pago (`REC-000002`) |
| Por recibo o factura | `GET {{base_url}}/payments?search=REC-000001` | 200 | Filtrado |
| Detalle | `GET {{base_url}}/payments/{{payment4_id}}` | 200 | `bank`, `notes`, `invoice` (con `balance`), datos de anulación |
| Medio inválido | `GET {{base_url}}/payments?method=bitcoin` | 422 | `VALIDATION_ERROR` |
| Facturas con saldo | `GET {{base_url}}/invoices` | 200 | Cada factura con `balance` y `payment_status` |

**Resumen de caja** (por defecto, el día de hoy; acepta `date_from` y `date_to`):

`GET {{base_url}}/payments/summary`

| Campo | Valor esperado |
|---|---|
| `payments_count` / `total` | `4` / `"352550.00"` (sin el pago anulado) |
| `by_method` efectivo | `payments_count` `3`, `total` `"100550.00"` (100.000 + 200 + 350) |
| `by_method` tarjeta | `payments_count` `1`, `total` `"252000.00"` |
| `by_method` transferencia / cheque | `"0.00"` / `"0.00"` |
| `cash.received` / `cash.change` | `"100850.00"` / `"300.00"` (en 16.3 se entregaron 500 por un pago de 200) |
| `cancelled_count` | `1` |

| Caso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Período sin pagos | `GET {{base_url}}/payments/summary?date_from=2020-01-01&date_to=2020-01-31` | 200 | `total` `"0.00"` |
| Rango invertido | `GET {{base_url}}/payments/summary?date_from=2026-02-01&date_to=2026-01-01` | 422 | `VALIDATION_ERROR` |

> Los totales asumen que no hubo otros pagos ese día.

### 16.7 Permisos del mecánico (token = `{{token_mec}}`)

| Caso | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| Registrar pago | `POST {{base_url}}/invoices/{{invoice4_id}}/payments` | 403 | `FORBIDDEN` |
| Listar pagos | `GET {{base_url}}/payments` | 403 | `FORBIDDEN` |
| Resumen de caja | `GET {{base_url}}/payments/summary` | 403 | `FORBIDDEN` |
| Anular pago | `POST {{base_url}}/payments/{{payment1_id}}/cancel` | 403 | `FORBIDDEN` |

---

## FASE 17 — Entrega del vehículo

Token: administrador salvo indicación.

> La entrega se registra **por recepción**. Requisitos: ninguna OT de la recepción abierta, al menos una completada y cada OT completada con **factura emitida**; si la factura es **al contado** debe estar **totalmente pagada** (a crédito se entrega con saldo).
> Al entregar, la recepción pasa a `delivered` y el kilometraje del vehículo se actualiza (solo si es mayor). Comprobante `ENT-000001`.
> La entrega no se edita: se **anula** con motivo y la recepción vuelve a su estado anterior.

### 17.1 Recepción con OT abiertas

`POST {{base_url}}/receptions/{{reception_id}}/delivery` con el body de 17.3.

Esperado: `422 DELIVERY_WORK_ORDERS_OPEN`. La recepción de las fases anteriores tiene OT sin terminar (por ejemplo `{{work_order4_id}}` y `{{work_order7_id}}`); el mensaje las lista.

### 17.2 Preparar una recepción entregable

Con el vehículo `{{vehicle2_id}}` (M.5.1, del cliente `{{client_id}}`):

1. `POST {{base_url}}/receptions` con el body de M.6 cambiando `"vehicle_id": {{vehicle2_id}}` y `"mileage": 30000` → `reception2_id`.
2. `POST {{base_url}}/budgets` con `{ "reception_id": {{reception2_id}} }` → `budget8_id`.
3. `POST {{base_url}}/budgets/{{budget8_id}}/items` con `{ "type": "labor", "description": "Revision general", "quantity": 1, "unit_price": 1000 }`.
4. `POST .../budgets/{{budget8_id}}/send` y `POST .../budgets/{{budget8_id}}/approve`.
5. `POST {{base_url}}/work-orders` con `{ "budget_id": {{budget8_id}}, "mechanic_id": {{mec1_id}} }` → `work_order8_id` (guardar el id del ítem → `work_order8_item_id`).

Casos de error mientras se avanza (body de 17.3):

| Momento | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| OT recién creada (`pending`) | `POST {{base_url}}/receptions/{{reception2_id}}/delivery` | 422 | `DELIVERY_WORK_ORDERS_OPEN` |

6. Iniciar la OT, iniciar y completar el ítem, completar la OT (`.../work-orders/{{work_order8_id}}/start`, `.../items/{{work_order8_item_id}}/start`, `.../items/{{work_order8_item_id}}/complete`, `.../work-orders/{{work_order8_id}}/complete`).

| Momento | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| OT completada sin factura | `POST {{base_url}}/receptions/{{reception2_id}}/delivery` | 422 | `DELIVERY_WORK_ORDER_NOT_INVOICED` |

7. `POST {{base_url}}/work-orders/{{work_order8_id}}/invoice` → `invoice5_id`, `totals.total` `"1100.00"`.

| Momento | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| Factura en borrador | `POST {{base_url}}/receptions/{{reception2_id}}/delivery` | 422 | `DELIVERY_WORK_ORDER_NOT_INVOICED` |

8. `POST {{base_url}}/invoices/{{invoice5_id}}/issue` → `number` `"001-001-0000005"` (contado).

| Momento | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| Contado sin pagar | `POST {{base_url}}/receptions/{{reception2_id}}/delivery` | 422 | `DELIVERY_INVOICE_NOT_PAID` |

9. `POST {{base_url}}/invoices/{{invoice5_id}}/payments` con `{ "amount": 1100, "method": "cash" }` → `201`, `invoice.payment_status` `paid`.

### 17.3 Registrar la entrega

`POST {{base_url}}/receptions/{{reception2_id}}/delivery`

```json
{
  "final_mileage": 30150,
  "fuel_level": "half",
  "received_by_name": "Lucia Benitez",
  "received_by_document": "QA-1001",
  "customer_accepted": true,
  "observations": "Se entrega lavado."
}
```

> Para adjuntar la firma usar **form-data** (no JSON): los mismos campos como texto (`customer_accepted` = `1`) y `signature` de tipo *File* (jpg, png o webp, máximo 2 MB).

Esperado: `201` → `delivery_id`.

| Campo | Valor esperado |
|---|---|
| `delivery_number` / `status.value` | `"ENT-000001"` / `delivered` |
| `final_mileage` / `fuel_level` | `30150` / `half` |
| `received_by` | `name` `"Lucia Benitez"`, `document` `"QA-1001"` |
| `customer_accepted` | `true` |
| `reception.status` | `delivered` |
| `vehicle.mileage` | `30150` |
| `signature_url` | URL de la imagen si se adjuntó; `null` si no |

Casos de error:

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Body vacío | `{}` | 422 | `VALIDATION_ERROR` (`final_mileage`, `received_by_name`, `received_by_document`, `customer_accepted`) |
| Sin conformidad | `{ ..., "customer_accepted": false }` | 422 | `VALIDATION_ERROR` (`errors.customer_accepted`) |
| Kilometraje menor al de la recepción | `{ ..., "final_mileage": 29000 }` | 422 | `DELIVERY_MILEAGE_BELOW_RECEPTION` |
| Combustible inválido | `{ ..., "fuel_level": "lleno" }` | 422 | `VALIDATION_ERROR` (`errors.fuel_level`) |
| Nombre con números | `{ ..., "received_by_name": "Ana 123" }` | 422 | `VALIDATION_ERROR` (`errors.received_by_name`) |
| Firma que no es imagen | form-data con `signature` = PDF | 422 | `VALIDATION_ERROR` (`errors.signature`) |
| Entregar otra vez | mismo body | 409 | `DELIVERY_ALREADY_REGISTERED` |
| Recepción inexistente | `POST {{base_url}}/receptions/999999/delivery` | 404 | `RESOURCE_NOT_FOUND` |

> Los casos de validación conviene probarlos **antes** de la entrega válida (después, la recepción ya está entregada).

### 17.4 Anular la entrega

**Caso borde (antes de anular): el vehículo ya reingresó.**

1. Crear una recepción nueva para el mismo vehículo de la entrega (body de M.6 con ese `client_id` y `vehicle_id`) → `201` → `returned_reception_id`. Se permite porque la recepción anterior está `delivered`.
2. `POST {{base_url}}/vehicle-deliveries/{{delivery_id}}/cancel` con `{ "reason": "Se registro por error" }` → `409 DELIVERY_VEHICLE_HAS_OPEN_RECEPTION` (anular reabriría la recepción anterior y el vehículo tendría dos abiertas). La entrega sigue vigente.
3. `POST {{base_url}}/receptions/{{returned_reception_id}}/cancel` con `{ "reason": "Ingreso duplicado" }` → `200` (o `DELETE {{base_url}}/receptions/{{returned_reception_id}}`, permitido porque está `pending`).

Ahora sí, anular:

`POST {{base_url}}/vehicle-deliveries/{{delivery_id}}/cancel`

```json
{ "reason": "Se registro por error" }
```

Esperado: `200`, `status.value` `cancelled`, `cancellation_reason` informado, `reception.status` vuelve a su estado anterior, `completed` ("Terminado": sus OT están completadas). El kilometraje del vehículo se conserva.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Sin motivo | `{}` | 422 | `VALIDATION_ERROR` (`errors.reason`) |
| Anular otra vez | `{ "reason": "x" }` | 422 | `DELIVERY_NOT_CANCELLABLE` |

Volver a entregar con el body de 17.3 → `201`, `delivery_number` `"ENT-000002"` (el número anulado no se reutiliza) → `delivery2_id`.

### 17.5 Listado y detalle

| Caso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Todas | `GET {{base_url}}/vehicle-deliveries` | 200 | 2 entregas con `meta.pagination`; cada una con `plate` y `client_name` |
| Por recepción | `GET {{base_url}}/vehicle-deliveries?reception_id={{reception2_id}}` | 200 | 2 entregas |
| Vigentes | `GET {{base_url}}/vehicle-deliveries?status=delivered` | 200 | Solo `ENT-000002` |
| Por número, chapa o quien retira | `GET {{base_url}}/vehicle-deliveries?search=QAB456` | 200 | Filtrado |
| Por fecha | `GET {{base_url}}/vehicle-deliveries?date_from=<hoy>&date_to=<hoy>` | 200 | Entregas de hoy |
| Estado inválido | `GET {{base_url}}/vehicle-deliveries?status=retirado` | 422 | `VALIDATION_ERROR` |
| Detalle | `GET {{base_url}}/vehicle-deliveries/{{delivery2_id}}` | 200 | `reception`, `vehicle`, `client`, `received_by` |
| Inexistente | `GET {{base_url}}/vehicle-deliveries/999999` | 404 | `RESOURCE_NOT_FOUND` |

### 17.6 Permisos del mecánico (token = `{{token_mec}}`)

| Caso | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| Registrar entrega | `POST {{base_url}}/receptions/{{reception2_id}}/delivery` | 403 | `FORBIDDEN` |
| Listar entregas | `GET {{base_url}}/vehicle-deliveries` | 403 | `FORBIDDEN` |
| Anular | `POST {{base_url}}/vehicle-deliveries/{{delivery2_id}}/cancel` | 403 | `FORBIDDEN` |

---

## FASE 18 — Historial del vehículo

Token: administrador salvo indicación.

> Solo lectura: reúne recepciones, diagnósticos, presupuestos, OT, repuestos, facturas y entregas del vehículo.
> Lo **facturado y los saldos** los ve todo el que tiene el permiso `vehicle-history.view`; los **costos y el margen** (`economics`, `unit_cost`, `total_cost`) solo el administrador.

### 18.1 Historial de un vehículo con una visita

`GET {{base_url}}/vehicles/{{vehicle2_id}}/history` (vehículo `QAB456`, FASE 17)

| Campo | Valor esperado |
|---|---|
| `vehicle.plate` | `"QAB456"` |
| `summary.visits` / `summary.work_orders_completed` | `1` / `1` |
| `summary.last_mileage` | `30150` (km de la entrega vigente) |
| `summary.invoiced_total` / `summary.balance_due` | `"1100.00"` / `"0.00"` |
| `summary.economics` | presente (`sale_total`, `cost_total`, `gross_margin`) |
| `timeline` | 1 visita: `mileage_in` `30000`, `status` `delivered` |
| `timeline[0].work_orders[0]` | `code` de `{{work_order8_id}}`, `jobs` con "Revision general", `invoice.payment_status` `paid` |
| `timeline[0].delivery` | `delivery_number` `"ENT-000002"` (la anulada no aparece), `mileage_out` `30150` |

### 18.2 Vehículo con varias visitas

`GET {{base_url}}/vehicles/{{vehicle_id}}/history` (vehículo `QAA123`, fases base a 16)

Esperado: `200`, `timeline` con la recepción `{{reception_id}}` y todas sus OT de las fases anteriores con su estado actual (completadas, canceladas y abiertas); las OT facturadas muestran su factura con saldo. Paginación: `?per_page=1` → `meta.pagination`.

### 18.3 Repuestos usados

`GET {{base_url}}/vehicles/{{vehicle_id}}/history/parts`

Esperado: `200`, 1 línea: aceite de `{{work_order2_id}}` con `quantity` `"1.500"` (1,7 consumido − 0,2 devuelto), `total_price` `"225.00"`, `total_cost` `"165.00"`. Las líneas canceladas o devueltas por completo no aparecen.

`GET {{base_url}}/vehicles/{{vehicle2_id}}/history/parts` → `200`, `data` vacío (solo mano de obra).

### 18.4 Kilometraje

`GET {{base_url}}/vehicles/{{vehicle2_id}}/history/mileage`

| Lectura | `mileage` | `source` | `decreased` |
|---|---|---|---|
| 1 | `30000` | `reception` | `false` |
| 2 | `30150` | `delivery` | `false` |

> La entrega anulada (`ENT-000001`) no cuenta. `decreased` es `true` cuando una lectura es menor que la anterior (posible error de carga del kilometraje).

### 18.5 Casos de error

| Caso | Método / Endpoint | HTTP | error_code |
|---|---|---|---|
| Vehículo inexistente | `GET {{base_url}}/vehicles/999999/history` | 404 | `RESOURCE_NOT_FOUND` |
| Paginación fuera de rango | `GET {{base_url}}/vehicles/{{vehicle2_id}}/history?per_page=500` | 422 | `VALIDATION_ERROR` |

### 18.6 Permisos

| Caso | Token | Método / Endpoint | HTTP | Resultado |
|---|---|---|---|---|
| Mecánico | `{{token_mec}}` | `GET {{base_url}}/vehicles/{{vehicle2_id}}/history` | 200 | Sin `summary.economics` ni `economics` por OT; sí `invoiced_total` |
| Mecánico, repuestos | `{{token_mec}}` | `GET {{base_url}}/vehicles/{{vehicle_id}}/history/parts` | 200 | Sin `unit_cost` ni `total_cost` |
| Cajero | `{{token_caja}}` (B.6) | `GET {{base_url}}/vehicles/{{vehicle2_id}}/history` | 200 | Sin costos |

---

## FASE 19 — Mantenimiento preventivo

Token: administrador salvo indicación. Vehículo de referencia: `{{vehicle2_id}}` (`QAB456`, FASE 17).

> Un **plan** define cada cuántos km y/o días se hace un mantenimiento. Vence por km o por fecha, lo que ocurra primero.
> Se registra **solo** al completar una OT con el servicio del plan, o **a mano**. El km actual se **estima** con el promedio km/día entre lecturas.
> Estados: `unknown` (sin registro), `ok`, `due_soon` (faltan ≤ 1.000 km o ≤ 30 días), `overdue`.
> Si la base se sembró con `MaintenancePlanSeeder` ya existen 3 planes de ejemplo (aceite, rotación, alineación).

### 19.1 Crear planes

`POST {{base_url}}/maintenance-plans`

```json
{
  "name": "Alineacion periodica",
  "description": "Alineacion y balanceo preventivo.",
  "service_id": {{service_id}},
  "interval_km": 10000,
  "interval_days": 365
}
```

Esperado: `201`, `is_active` `true`, `service.name` `"Alineacion QA"` (FASE 13) → `maint_plan_id`.

Plan solo por tiempo: `{ "name": "Revision de frenos", "interval_days": 30 }` → `201` → `brakes_plan_id`.

| Caso | Método / Body | HTTP | error_code |
|---|---|---|---|
| Sin intervalos | `POST` `{ "name": "Plan QA" }` | 422 | `VALIDATION_ERROR` (`interval_km`, `interval_days`) |
| Nombre repetido | `POST` `{ "name": "Revision de frenos", "interval_days": 10 }` | 422 | `VALIDATION_ERROR` (`errors.name`) |
| Días fuera de rango | `POST` `{ "name": "Plan QA", "interval_days": 5000 }` | 422 | `VALIDATION_ERROR` (`errors.interval_days`) |
| Servicio inexistente | `POST` `{ "name": "Plan QA", "interval_km": 1000, "service_id": 999999 }` | 422 | `VALIDATION_ERROR` (`errors.service_id`) |
| Dejar el plan sin intervalos | `PATCH {{base_url}}/maintenance-plans/{{brakes_plan_id}}` `{ "interval_days": null }` | 422 | `MAINTENANCE_INTERVAL_REQUIRED` |

Listado: `GET {{base_url}}/maintenance-plans` (filtros `search`, `is_active`).

### 19.2 Estado del vehículo antes de registrar

`GET {{base_url}}/vehicles/{{vehicle2_id}}/maintenances`

Esperado: `200`, `current_mileage.mileage` `30150` (última lectura: entrega de 17.3; `estimated` `false` porque las lecturas son del mismo día) y cada plan activo con `status.value` `unknown`.

### 19.3 Registro manual

`POST {{base_url}}/vehicles/{{vehicle2_id}}/maintenances`

```json
{
  "maintenance_plan_id": {{maint_plan_id}},
  "done_at": "<hoy>",
  "mileage": 30150,
  "notes": "Hecho en otro taller"
}
```

Esperado: `201`, `last_done_mileage` `30150`, `next_due_mileage` `40150`, `next_due_at` hoy + 365 días, `status.value` `ok`, `remaining_km` `10000`.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Fecha anterior al último registro | `{ ..., "done_at": "<ayer>" }` | 422 | `MAINTENANCE_DONE_OUT_OF_ORDER` |
| Menos km que el último registro | `{ ..., "mileage": 30000 }` | 422 | `MAINTENANCE_MILEAGE_BELOW_LAST` |
| Fecha futura | `{ ..., "done_at": "<mañana>" }` | 422 | `VALIDATION_ERROR` (`errors.done_at`) |
| Sin plan | `{ "done_at": "<hoy>" }` | 422 | `VALIDATION_ERROR` (`errors.maintenance_plan_id`) |

Frenos hechos hace 35 días: `{ "maintenance_plan_id": {{brakes_plan_id}}, "done_at": "<hoy − 35 días>" }` → `201`, `status.value` `overdue`, `remaining_days` `-5`.

### 19.4 Registro automático desde la OT

1. `POST {{base_url}}/receptions` con el body de M.6 cambiando `"vehicle_id": {{vehicle2_id}}` y `"mileage": 31000` → `reception3_id`.
2. Presupuesto con `{ "reception_id": {{reception3_id}} }` → `budget9_id`; ítem `{ "type": "service", "service_id": {{service_id}}, "description": "Alineacion", "quantity": 1 }`; enviar y aprobar.
3. OT con `{ "budget_id": {{budget9_id}}, "mechanic_id": {{mec1_id}} }` → `work_order9_id`; iniciar, iniciar y completar el ítem, completar la OT.
4. `GET {{base_url}}/vehicles/{{vehicle2_id}}/maintenances` → "Alineacion periodica": `last_done_mileage` `31000`, `work_order_id` `{{work_order9_id}}`, `next_due_mileage` `41000`.

> Las OT completadas **antes** de crear el plan no se registran retroactivamente.

### 19.5 Intervalo propio del vehículo

`PATCH {{base_url}}/vehicles/{{vehicle2_id}}/maintenances/{{maint_plan_id}}`

```json
{ "interval_km": 20000 }
```

Esperado: `200`, `is_custom_interval` `true`, `next_due_mileage` `51000`. Con `{ "interval_km": null }` vuelve al intervalo del plan (`next_due_mileage` `41000`, `is_custom_interval` `false`).

### 19.6 Alertas

`GET {{base_url}}/maintenance/alerts`

Esperado: `200`; incluye "Revision de frenos" de `QAB456` con `status.value` `overdue`, `client.phone` del cliente y `vehicle.estimated_mileage`. `meta.counts` con la cantidad de `overdue` y `due_soon`. Los vencidos aparecen primero.

| Caso | Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Solo por vencer | `GET {{base_url}}/maintenance/alerts?status=due_soon` | 200 | Filtrado |
| Estado inválido | `GET {{base_url}}/maintenance/alerts?status=ok` | 422 | `VALIDATION_ERROR` |
| Plan desactivado | `PATCH .../maintenance-plans/{{brakes_plan_id}}` `{ "is_active": false }` y repetir | 200 | La alerta de frenos ya no aparece |
| Eliminar plan con seguimiento | `DELETE {{base_url}}/maintenance-plans/{{brakes_plan_id}}` | 409 | `MAINTENANCE_PLAN_IN_USE` |

### 19.7 Permisos

| Caso | Token | Endpoint | HTTP |
|---|---|---|---|
| Estado del vehículo | `{{token_mec}}` | `GET {{base_url}}/vehicles/{{vehicle2_id}}/maintenances` | 200 |
| Registrar | `{{token_mec}}` | `POST {{base_url}}/vehicles/{{vehicle2_id}}/maintenances` | 403 `FORBIDDEN` |
| Alertas | `{{token_mec}}` | `GET {{base_url}}/maintenance/alerts` | 403 `FORBIDDEN` |
| Alertas | `{{token_caja}}` | `GET {{base_url}}/maintenance/alerts` | 403 `FORBIDDEN` |
| Crear plan | `{{token_mec}}` | `POST {{base_url}}/maintenance-plans` | 403 `FORBIDDEN` |

---

## FASE 20 — Reportes y dashboard

Token: administrador salvo indicación. Todos los reportes aceptan `date_from` y `date_to` (`AAAA-MM-DD`); sin fechas toman el **mes en curso**.

> Los valores dependen de todo lo cargado en las fases anteriores: esta sección verifica la **estructura** y la **coherencia** con lo ya probado, no montos fijos.
> Ventas y clientes: importes **con IVA** (facturas, por fecha de emisión). Rentabilidad y productividad: **sin IVA** (cierres, por fecha de cierre de la OT).

### 20.1 Dashboard

`GET {{base_url}}/dashboard`

Esperado (admin): `200` con los bloques `workshop`, `sales`, `budgets`, `profitability`, `productivity`, `inventory`, `maintenance`.

| Bloque | Qué verificar |
|---|---|
| `workshop` | `vehicles_in_shop` (recepciones no entregadas ni canceladas), `open_work_orders` por estado (incluye `{{work_order7_id}}` pendiente), `completed_this_month` |
| `sales` | `invoiced_this_month`, `collected_this_month` (igual al `total` de `GET .../payments/summary` del mes), `receivables` |
| `budgets` | `awaiting_response` (presupuestos `sent`), `approval_rate_this_month` |
| `inventory` | `stock_value`, `below_minimum` (incluye `REF-REF-001` del seeder) |
| `maintenance` | `overdue` / `due_soon` (igual a `meta.counts` de `GET .../maintenance/alerts`) |

| Token | Bloques visibles |
|---|---|
| `{{token_mec}}` | solo `workshop` |
| `{{token_caja}}` (B.6) | `workshop`, `sales` |

### 20.2 Ventas

`GET {{base_url}}/reports/sales`

Esperado: `200`; `totals` (`invoices_count`, `total`, `taxed_10`, `iva_10`, `average_ticket`, …), `by_period` (por día), `by_condition` (`cash`/`credit`), `collected.by_method` y `cancelled` (incluye `{{invoice2_id}}`, anulada en 15.7).

| Caso | Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Por mes | `GET {{base_url}}/reports/sales?group_by=month` | 200 | `by_period[].period` con formato `AAAA-MM` |
| Período sin ventas | `GET {{base_url}}/reports/sales?date_from=2020-01-01&date_to=2020-01-31` | 200 | `invoices_count` `0`, `average_ticket` `"0.00"` |
| Agrupación inválida | `GET {{base_url}}/reports/sales?group_by=week` | 422 | `VALIDATION_ERROR` |
| Rango invertido | `GET {{base_url}}/reports/sales?date_from=2026-02-01&date_to=2026-01-01` | 422 | `VALIDATION_ERROR` |

### 20.3 Presupuestos

`GET {{base_url}}/reports/budgets` → `200`; `by_status` con cada estado (`draft`, `sent`, `approved`, `rejected`, `cancelled`), cantidad y monto; `approval_rate` = aprobados / (aprobados + rechazados) × 100 (`null` si no hay ninguno decidido).

### 20.4 Rentabilidad (solo administrador)

`GET {{base_url}}/reports/profitability` → `200`; `totals.sale_total` y `totals.gross_margin` = suma de los `economic_summary` de las OT completadas en el período (por ejemplo `{{work_order5_id}}`: venta `320000.00`, margen `96400.00`, ver 14.2); `lowest_margin` / `highest_margin` con hasta 5 OT; `incomplete_cost_count`.

### 20.5 Productividad

`GET {{base_url}}/reports/productivity` → `200`; una fila por mecánico (MEC-001, MEC-002) con `work_orders_as_responsible`, `jobs_completed`, `labor_sale_attributed`, `commission_total` (coincide con `GET .../mechanics/{id}/commissions` del mes) y `hours_logged` (MEC-001 incluye las 3,5 h de 13.10).

### 20.6 Inventario

`GET {{base_url}}/reports/inventory` → `200`; `stock_value`, `below_minimum`, `top_consumed` (el aceite `QA-ACE-001` aparece con su consumo neto de 12.9/12.10) y `movements_by_type`.

### 20.7 Clientes

`GET {{base_url}}/reports/clients` → `200`; `top_clients` (Lucia Benitez con las facturas emitidas), `new_clients`, `receivables` (saldos de facturas a crédito sin pagar), `top_vehicles` (`QAA123` con sus visitas).

### 20.8 Acceso por área

| Token | Puede ver | 403 `FORBIDDEN` en |
|---|---|---|
| `{{token_caja}}` | `reports/sales`, `reports/clients` | `reports/budgets`, `reports/profitability` |
| `{{token_mec}}` | — (solo `dashboard`) | todos los `reports/*` |

> Recepcionista: `reports/budgets`; jefe de taller: `reports/productivity`; depósito: `reports/inventory`; rentabilidad: solo administrador.

---

## FASE 21 — Compras

Token: administrador. La factura del proveedor se carga **con IVA incluido**; al recibir, el stock entra al costo **sin
IVA** (costo × 100 / (100 + tasa)). Usa `{{supplier_id}}` (FASE 10) y los repuestos `{{part_id}}` (litros) y
`{{part_unit_id}}` (unidades).

### 21.1 Crear la compra en borrador

`POST {{base_url}}/purchases`

```json
{
  "supplier_id": {{supplier_id}},
  "supplier_document_number": "001-001-0004567",
  "supplier_document_date": "2026-10-20",
  "supplier_stamp_number": "12345678",
  "condition": "credit",
  "due_date": "2026-11-20",
  "notes": "Reposición mensual"
}
```

Esperado: `201`, `code` `COM-…`, `status.value` `draft`, `totals.total` `"0.00"`. Guardar `data.id` → `purchase2_id`.

### 21.2 Cargar líneas

`POST {{base_url}}/purchases/{{purchase2_id}}/items`, una por vez:

| Body | Resultado de la línea |
|---|---|
| `{ "part_id": {{part_unit_id}}, "quantity": 2, "unit_cost": 38500 }` (tasa por defecto 10) | `total` `"77000.00"`, `net_unit_cost` `"35000.0000"` |
| `{ "part_id": {{part_id}}, "quantity": 4, "unit_cost": 157.5, "tax_rate": 5 }` | `total` `"630.00"`, `net_unit_cost` `"150.0000"` |

Esperado tras la segunda: `201`, `totals.taxed_10` `"77000.00"`, `totals.iva_10` `"7000.00"` (÷ 11), `totals.taxed_5`
`"630.00"`, `totals.iva_5` `"30.00"` (÷ 21), `totals.total` `"77630.00"`, `totals.net_total` `"70600.00"`. Guardar
`data.items.1.id` → `purchase_item_id`.

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Editar línea | `PATCH {{base_url}}/purchases/{{purchase2_id}}/items/{{purchase_item_id}}` | `{ "quantity": 5 }` | 200 | `total` `"788.00"` (5 × 157,5 redondeado) |
| Volver a 4 | mismo | `{ "quantity": 4 }` | 200 | `totals.total` `"77630.00"` |
| Línea de otra compra | `PATCH {{base_url}}/purchases/{{purchase_id}}/items/{{purchase_item_id}}` | `{ "quantity": 1 }` | 404 | `RESOURCE_NOT_FOUND` |

### 21.3 Recibir

`POST {{base_url}}/purchases/{{purchase2_id}}/receive` → `200`, `status.value` `received`, `received_at` con fecha y
cada línea con `inventory_movement_id`.

Verificar: `GET {{base_url}}/inventory/movements?type=purchase` incluye las dos líneas con `document_number`
`001-001-0004567`; en `GET {{base_url}}/parts/{{part_unit_id}}` el proveedor queda vinculado con `last_cost`
`"35000.00"` (se vincula solo si no lo estaba).

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Recibir de nuevo | `POST {{base_url}}/purchases/{{purchase2_id}}/receive` | — | 422 | `PURCHASE_NOT_RECEIVABLE` |
| Editar recibida | `PATCH {{base_url}}/purchases/{{purchase2_id}}` | `{ "notes": "x" }` | 422 | `PURCHASE_NOT_EDITABLE` |
| Agregar línea a recibida | `POST {{base_url}}/purchases/{{purchase2_id}}/items` | body de 21.2 | 422 | `PURCHASE_NOT_EDITABLE` |
| Eliminar recibida | `DELETE {{base_url}}/purchases/{{purchase2_id}}` | — | 422 | `PURCHASE_NOT_EDITABLE` |

### 21.4 Validaciones al recibir

Crear una compra nueva (body de 21.1 sin `supplier_document_number` y con `"condition": "cash"`) → `purchase3_id`.

| Paso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Sin líneas | `POST {{base_url}}/purchases/{{purchase3_id}}/receive` | — | 422 | `PURCHASE_WITHOUT_ITEMS` |
| Agregar una línea | `POST {{base_url}}/purchases/{{purchase3_id}}/items` | `{ "part_id": {{part_id}}, "quantity": 1, "unit_cost": 110 }` | 201 | — |
| Sin número de factura | `POST {{base_url}}/purchases/{{purchase3_id}}/receive` | — | 422 | `PURCHASE_DOCUMENT_REQUIRED` |
| A crédito sin vencimiento | `PATCH {{base_url}}/purchases/{{purchase3_id}}` | `{ "supplier_document_number": "001-001-0004567", "condition": "credit", "due_date": null }` | 200 | — |
| Recibir | `POST {{base_url}}/purchases/{{purchase3_id}}/receive` | — | 422 | `PURCHASE_DUE_DATE_INVALID` |
| Al contado | `PATCH {{base_url}}/purchases/{{purchase3_id}}` | `{ "condition": "cash" }` | 200 | — |
| Factura ya cargada (mismo proveedor y número que 21.1) | `POST {{base_url}}/purchases/{{purchase3_id}}/receive` | — | 409 | `PURCHASE_DUPLICATE_DOCUMENT` |
| Número nuevo | `PATCH {{base_url}}/purchases/{{purchase3_id}}` | `{ "supplier_document_number": "001-001-0004568" }` | 200 | — |
| Descartar el borrador | `DELETE {{base_url}}/purchases/{{purchase3_id}}` | — | 200 | — |

### 21.5 Errores de carga

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Body vacío | `POST {{base_url}}/purchases` | `{}` | 422 | `VALIDATION_ERROR` (`supplier_id`) |
| Fecha futura | `POST {{base_url}}/purchases` | `{ "supplier_id": {{supplier_id}}, "supplier_document_date": "2099-01-01" }` | 422 | `VALIDATION_ERROR` |
| Proveedor inactivo | `POST {{base_url}}/purchases` | `{ "supplier_id": <proveedor inactivo> }` | 422 | `SUPPLIER_INACTIVE` |
| Tasa inválida | `POST {{base_url}}/purchases/{{purchase_draft_id}}/items` (un borrador nuevo, body de 21.1) | `{ "part_id": {{part_id}}, "quantity": 1, "unit_cost": 1, "tax_rate": 7 }` | 422 | `VALIDATION_ERROR` |
| Fracción en repuesto por unidad | ídem | `{ "part_id": {{part_unit_id}}, "quantity": 1.5, "unit_cost": 100 }` | 422 | `INVENTORY_FRACTIONAL_QUANTITY_NOT_ALLOWED` |
| Repuesto descontinuado | ídem | `{ "part_id": {{part_status_id}}, "quantity": 1, "unit_cost": 100 }` | 422 | `PART_DISCONTINUED_NO_ENTRY` |

### 21.6 Anular

`POST {{base_url}}/purchases/{{purchase2_id}}/cancel` con `{ "reason": "Factura cargada con error" }`.

Si parte del stock de la compra ya se consumió o está reservada y no alcanza el disponible → `422 INSUFFICIENT_STOCK`
(el mensaje indica el repuesto) y no cambia nada. Con stock suficiente → `200`, `status.value` `cancelled`,
`cancellation_reason`, y un movimiento `purchase_cancellation` (salida al costo promedio) por línea. El `last_cost` del
proveedor no se revierte.

| Caso | Body | HTTP | error_code |
|---|---|---|---|
| Sin motivo | `{}` | 422 | `VALIDATION_ERROR` (`errors.reason`) |
| Anular de nuevo | `{ "reason": "Otra vez" }` | 422 | `PURCHASE_NOT_CANCELLABLE` |
| Anular un borrador | `POST .../purchases/{id-borrador}/cancel` | 422 | `PURCHASE_NOT_CANCELLABLE` (el borrador se elimina) |

### 21.7 Listado

| Caso | Método / Endpoint | HTTP | Resultado |
|---|---|---|---|
| Todas | `GET {{base_url}}/purchases` | 200 | `meta.pagination` |
| Por estado | `GET {{base_url}}/purchases?status=received` | 200 | Solo recibidas |
| Por proveedor | `GET {{base_url}}/purchases?supplier_id={{supplier_id}}` | 200 | Filtrado |
| Búsqueda | `GET {{base_url}}/purchases?search=0004567` | 200 | Por código o número de factura |
| Por fecha de factura | `GET {{base_url}}/purchases?date_from=2026-10-01&date_to=2026-10-31` | 200 | Filtrado |
| Estado inexistente | `GET {{base_url}}/purchases?status=paid` | 422 | `VALIDATION_ERROR` |

### 21.8 Permisos

| Token | Puede | 403 `FORBIDDEN` en |
|---|---|---|
| `{{token_caja}}` | `GET purchases`, `GET purchases/{id}` | crear, editar, recibir, anular |
| `{{token_mec}}` | — | todo `purchases/*` |

> Depósito: todo el módulo. Jefe de taller: solo consulta, como el cajero.

> Desde la FASE 22, una compra con pagos vigentes no se anula (`PURCHASE_HAS_PAYMENTS`, ver 22.4).

---

## FASE 22 — Pagos a proveedores

Token: administrador. Cada compra recibida (contado o crédito) queda con saldo hasta registrar sus pagos. Montos en
guaraníes enteros.

Preparar una compra recibida a crédito: repetir 21.1 con `"supplier_document_number": "001-001-0007001"` y
`"supplier_document_date"` de hoy, agregar `{ "part_id": {{part_unit_id}}, "quantity": 2, "unit_cost": 55000 }` (21.2)
y recibirla (21.3). Guardar `data.id` → `purchase4_id`. La respuesta trae `payment_summary`: `paid_total` `"0.00"`,
`balance` `"110000.00"`, `status` `unpaid`.

### 22.1 Pago parcial en efectivo

`POST {{base_url}}/purchases/{{purchase4_id}}/payments`

```json
{
  "amount": 40000,
  "method": "cash",
  "supplier_receipt_number": "R-0099",
  "notes": "Entrega a cuenta"
}
```

Esperado: `201`, `code` `PAG-…`, `payment_date` de hoy, `reference` y `bank` `null`, `purchase.balance` `"70000.00"`,
`purchase.payment_status` `partial`. Guardar `data.id` → `supplier_payment_id`.

### 22.2 Saldo con cheque

`POST {{base_url}}/purchases/{{purchase4_id}}/payments`

```json
{
  "amount": 70000,
  "method": "check",
  "reference": "CH-000778",
  "bank": "Banco Basa"
}
```

Esperado: `201`, `purchase.balance` `"0.00"`, `purchase.payment_status` `paid`.

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Compra ya pagada | `POST {{base_url}}/purchases/{{purchase4_id}}/payments` | `{ "amount": 1, "method": "cash" }` | 422 | `PURCHASE_ALREADY_PAID` |
| Ver pagos de la compra | `GET {{base_url}}/purchases/{{purchase4_id}}/payments` | — | 200 | 2 pagos |
| Detalle de la compra | `GET {{base_url}}/purchases/{{purchase4_id}}` | — | 200 | `payment_summary.status` `paid` y `payments` |

### 22.3 Validaciones del pago

Los errores de validación se responden antes de mirar el saldo; los de saldo y fecha frente a la factura, en 22.4.

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Fecha futura, decimales, medio inválido | `POST {{base_url}}/purchases/{{purchase4_id}}/payments` | `{ "amount": 1.5, "method": "x", "payment_date": "2099-01-01" }` | 422 | `VALIDATION_ERROR` (`amount`, `method`, `payment_date`) |
| Cheque sin referencia ni banco | ídem | `{ "amount": 1000, "method": "check" }` | 422 | `VALIDATION_ERROR` (`reference`, `bank`) |
| Compra en borrador | `POST {{base_url}}/purchases/{id-borrador}/payments` | `{ "amount": 1000, "method": "cash" }` | 422 | `PURCHASE_NOT_PAYABLE` |
| Compra anulada | `POST {{base_url}}/purchases/{{purchase2_id}}/payments` | ídem | 422 | `PURCHASE_NOT_PAYABLE` |

### 22.4 Anular un pago

`POST {{base_url}}/supplier-payments/{{supplier_payment_id}}/cancel` con `{ "reason": "Cargado dos veces" }` → `200`,
`status.value` `cancelled`, `purchase.balance` `"40000.00"`, `purchase.payment_status` `partial`.

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Sin motivo | `POST {{base_url}}/supplier-payments/{{supplier_payment_id}}/cancel` | `{}` | 422 | `VALIDATION_ERROR` (`errors.reason`) |
| Anular de nuevo | ídem | `{ "reason": "Otra vez" }` | 422 | `SUPPLIER_PAYMENT_NOT_CANCELLABLE` |
| Supera el saldo (40.000) | `POST {{base_url}}/purchases/{{purchase4_id}}/payments` | `{ "amount": 40001, "method": "cash" }` | 422 | `SUPPLIER_PAYMENT_EXCEEDS_BALANCE` |
| Fecha anterior a la factura | ídem | `{ "amount": 1000, "method": "cash", "payment_date": "2020-01-01" }` | 422 | `SUPPLIER_PAYMENT_DATE_BEFORE_DOCUMENT` |
| Anular la compra con un pago vigente | `POST {{base_url}}/purchases/{{purchase4_id}}/cancel` | `{ "reason": "Error" }` | 422 | `PURCHASE_HAS_PAYMENTS` |

### 22.5 Listado y resumen de egresos

| Caso | Método / Endpoint | HTTP | Resultado |
|---|---|---|---|
| Todos | `GET {{base_url}}/supplier-payments` | 200 | `meta.pagination`, con `purchase_code` y `supplier_name` |
| Por proveedor / compra | `GET {{base_url}}/supplier-payments?supplier_id={{supplier_id}}&purchase_id={{purchase4_id}}` | 200 | Filtrado |
| Por medio y estado | `GET {{base_url}}/supplier-payments?method=check&status=registered` | 200 | Filtrado |
| Búsqueda | `GET {{base_url}}/supplier-payments?search=CH-000778` | 200 | Por código, referencia, recibo del proveedor, código o factura de la compra |
| Por fecha | `GET {{base_url}}/supplier-payments?date_from=2026-10-01&date_to=2026-10-31` | 200 | Filtrado |
| Detalle | `GET {{base_url}}/supplier-payments/{{supplier_payment_id}}` | 200 | Proveedor, compra con saldo y datos de anulación |
| Resumen (hoy) | `GET {{base_url}}/supplier-payments/summary` | 200 | `total` y `by_method` de pagos vigentes; `cancelled_count` |
| Resumen por período | `GET {{base_url}}/supplier-payments/summary?date_from=2026-10-01&date_to=2026-10-31` | 200 | — |

El resumen de caja de cobros (`GET payments/summary`) no incluye los pagos a proveedores.

### 22.6 Cuentas por pagar

`GET {{base_url}}/accounts-payable` → `200`: `as_of` (hoy), `balance`, `overdue`, `not_due` y `suppliers` (saldo por
proveedor, ordenado de mayor a menor, con `purchases_count` y `oldest_due_date`). Solo compras recibidas con saldo. Vence
en `due_date` (crédito) o en la fecha de la factura (contado); vencido = antes de hoy.

`GET {{base_url}}/accounts-payable?supplier_id={{supplier_id}}` → además, `suppliers.0.purchases` con cada compra
(`due_date`, `days_overdue`, `total`, `paid_total`, `balance`, `payment_status`). Proveedor inexistente → `422
VALIDATION_ERROR`.

### 22.7 Permisos

| Token | Puede | 403 `FORBIDDEN` en |
|---|---|---|
| `{{token_caja}}` | todo el módulo | — |
| `{{token_mec}}` | — | `supplier-payments/*`, `purchases/{id}/payments`, `accounts-payable` |

> Depósito y jefe de taller: consultan pagos (`GET supplier-payments`, detalle, pagos de la compra) y cuentas por
> pagar; no registran, no anulan ni ven el resumen de egresos.

---

## FASE 23 — Notificaciones

Token: administrador. Los emails salen por cola: en desarrollo, con `MAIL_MAILER=log` y `php artisan queue:work`
corriendo, cada email aparece en `storage/logs/laravel.log` y el aviso pasa de `pending` a `sent`. Sin `queue:work`
queda `pending`.

### 23.1 Baja de avisos del cliente

`PATCH {{base_url}}/clients/{{client_id}}` con `{ "notifications_enabled": false }` → `200`,
`data.notifications_enabled` `false`. Volver a `true` para seguir. Con `"notifications_enabled": "x"` → `422
VALIDATION_ERROR`.

### 23.2 Presupuesto enviado

Con un presupuesto en borrador con ítems de un cliente con email: `POST {{base_url}}/budgets/{{budget_id}}/send` →
`200`. Luego `GET {{base_url}}/clients/{{client_id}}/notifications` → `200`, `data.0.type.value` `budget_sent`,
`recipient` el email del cliente, `status.value` `sent` (o `pending` si la cola no corre). Guardar `data.0.id` →
`client_notification_id`.

| Caso | Resultado esperado |
|---|---|
| Cliente sin email (desde D8 la base no lo admite; solo con un email vacío) | Aviso `skipped`, `error` "El cliente no tiene email registrado." |
| Cliente con `notifications_enabled = false` | Aviso `skipped`, `error` "El cliente desactivó los avisos." |
| Rechazar, reabrir y volver a enviar | Un aviso nuevo por cada envío |
| Falla el servidor de correo | El envío del presupuesto responde `200`; el aviso queda `failed` con el error |

### 23.3 Vehículo listo

Al completar la última OT vigente de la recepción (`POST work-orders/{{work_order_id}}/complete`), la recepción pasa a
`completed` y se registra un aviso `vehicle_ready` (`subject.type` `reception`). Uno por recepción: si vuelve a
reparación y se completa de nuevo, no se repite (usar el reenvío).

### 23.4 Recordatorios diarios

`php artisan notifications:send-reminders` (lo corre el scheduler a la hora de `config/notifications.php`) imprime la
cantidad de avisos registrados. Se puede ejecutar varias veces: no repite avisos.

| Situación | Aviso |
|---|---|
| Factura emitida a crédito con saldo que vence en 0 a 3 días | `invoice_due_soon` (una vez) |
| Factura vencida con saldo | `invoice_overdue` el primer día y cada 7 días |
| Mantenimiento por vencer / vencido (ver `GET maintenance/alerts`) | `maintenance_due_soon` / `maintenance_overdue`, uno por estado y ciclo |
| Compras con saldo vencido | Aviso interno `accounts_payable_overdue` a cada cajero, uno por día |
| Factura a crédito cuyo RUC/CI se cambió en el borrador a otro destinatario (por ejemplo `80012345-6`) | El mismo aviso, `skipped` con `error` "La factura está a nombre de otro destinatario." (sin email). Cambiar CI `1234567` por RUC `1234567-8` del mismo cliente no lo omite. El reenvío manual responde 201 y va al cliente |

### 23.5 Listado, detalle y reenvío

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Todos | `GET {{base_url}}/client-notifications` | — | 200 | `meta.pagination`, con `client_name` |
| Filtros | `GET {{base_url}}/client-notifications?client_id={{client_id}}&type=budget_sent&status=sent&date_from=2026-10-01&date_to=2026-10-31` | — | 200 | Filtrado |
| Tipo inválido | `GET {{base_url}}/client-notifications?type=x` | — | 422 | `VALIDATION_ERROR` |
| Detalle | `GET {{base_url}}/client-notifications/{{client_notification_id}}` | — | 200 | `client` (con `notifications_enabled`), `subject.code`, `attempts`, `error`, `created_by` |
| Reenviar | `POST {{base_url}}/client-notifications/{{client_notification_id}}/resend` | — | 201 | Aviso nuevo con `resent_from_id` y `created_by` |
| Reenviar con el cliente sin email (defensivo desde D8) | ídem | — | 422 | `CLIENT_WITHOUT_EMAIL` |
| Reenviar con la baja activada | ídem | — | 422 | `CLIENT_NOTIFICATIONS_DISABLED` |
| Reenviar un aviso todavía en cola | ídem (aviso `pending`) | — | 409 | `CLIENT_NOTIFICATION_PENDING` |
| Reenviar con el documento eliminado | ídem | — | 422 | `CLIENT_NOTIFICATION_SUBJECT_UNAVAILABLE` |

### 23.6 Bandeja interna

Los avisos internos llegan a los usuarios de los roles configurados: presupuesto aprobado/rechazado → recepcionista y
jefe-taller; OT completada → cajero y recepcionista; repuesto sin stock al crear la OT o agregar un repuesto → depósito
y jefe-taller; cuentas por pagar vencidas → cajero. El administrador no los recibe.

Con `{{token_caja}}` después de completar una OT:

| Caso | Método / Endpoint | HTTP | Resultado |
|---|---|---|---|
| Mis avisos | `GET {{base_url}}/me/notifications` | 200 | `type.value` `work_order_completed`, `title`, `message`, `subject` (`type`, `id`, `code`), `read_at` `null`; `meta.unread_count` |
| Solo no leídos | `GET {{base_url}}/me/notifications?unread=1` | 200 | — |
| Por tipo | `GET {{base_url}}/me/notifications?type=work_order_completed` | 200 | — |
| Marcar uno | `PATCH {{base_url}}/me/notifications/{id}/read` | 200 | `read_at` con fecha |
| Marcar todos | `POST {{base_url}}/me/notifications/read-all` | 200 | `data.marked_count` |
| Aviso de otro usuario | `PATCH {{base_url}}/me/notifications/{id-ajeno}/read` | 404 | `RESOURCE_NOT_FOUND` |

### 23.7 Permisos

| Token | Puede | 403 `FORBIDDEN` en |
|---|---|---|
| `{{token_caja}}` | `client-notifications/*` (listar, ver, reenviar) | — |
| `{{token_mec}}` | su bandeja `me/notifications` | `client-notifications/*`, `clients/{id}/notifications` |

> La recepcionista tiene los mismos permisos que el cajero sobre los avisos a clientes. La bandeja `me/notifications`
> no requiere permisos.

---

## FASE 24 — Exportación y comprobantes imprimibles

Token: administrador. Las descargas devuelven el archivo, no JSON. En Postman, usar **Send and Download** o ver la
respuesta en la pestaña de vista previa. Los errores (403, 404, 422) siguen en JSON. Los datos del taller del
encabezado salen de las variables `COMPANY_*` del `.env`.

### 24.1 Comprobantes

| Caso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Presupuesto | `GET {{base_url}}/budgets/{{budget_id}}/pdf` | 200 | `application/pdf`, `presupuesto-BUD-….pdf`; en borrador, marca de agua `BORRADOR` (`RECHAZADO` / `CANCELADO` según el estado) |
| Factura emitida | `GET {{base_url}}/invoices/{{invoice_id}}/pdf` | 200 | `factura-001-001-….pdf`, con timbrado, IVA por tasa y la leyenda "no válido como documento fiscal" |
| Factura en borrador | `GET {{base_url}}/invoices/{id-borrador}/pdf` | 200 | `factura-borrador-{id}.pdf`, marca `BORRADOR` |
| Factura anulada | `GET {{base_url}}/invoices/{id-anulada}/pdf` | 200 | Marca `ANULADA` y el motivo |
| Recibo (pago de factura a crédito) | `GET {{base_url}}/payments/{id}/receipt` | 200 | `recibo-REC-….pdf`; anulado → marca `ANULADO` |
| Recibo de un pago al contado | `GET {{base_url}}/payments/{id}/receipt` | 422 | `PAYMENT_WITHOUT_RECEIPT` |
| Comprobante de entrega | `GET {{base_url}}/vehicle-deliveries/{id}/pdf` | 200 | `entrega-ENT-….pdf`, con km, quién retira, conformidad, OT y facturas, y la firma si se cargó |
| Documento inexistente | `GET {{base_url}}/invoices/999999/pdf` | 404 | `RESOURCE_NOT_FOUND` |

El email de "presupuesto enviado" (23.2) lleva el PDF del presupuesto adjunto. Con `MAIL_MAILER=log`, el adjunto
aparece codificado en el log.

### 24.2 Reportes

Mismo endpoint de cada reporte (FASE 20) con `format`:

| Caso | Método / Endpoint | HTTP | Resultado |
|---|---|---|---|
| Ventas en CSV | `GET {{base_url}}/reports/sales?format=csv&date_from=2026-10-01&date_to=2026-10-31` | 200 | `text/csv`, `reporte-ventas_2026-10-01_2026-10-31.csv`: título, período, "Resumen" (Concepto;Valor) y una tabla por lista ("Por período", "Por condición", "Cobrado — Por medio de pago") |
| Ventas en PDF | `GET {{base_url}}/reports/sales?format=pdf` | 200 | `application/pdf`, A4 apaisado, mes en curso |
| Los demás | `budgets`, `profitability`, `productivity`, `inventory`, `clients` con `?format=csv` o `?format=pdf` | 200 | — |
| Sin formato o `format=json` | `GET {{base_url}}/reports/sales` | 200 | JSON de siempre |
| Formato inválido | `GET {{base_url}}/reports/sales?format=xlsx` | 422 | `VALIDATION_ERROR` (`format`) |

El CSV abre directo en Excel en español: UTF-8 con BOM, separador `;` y coma decimal.

### 24.3 Permisos

Se usan los permisos de consulta existentes: `budgets.show`, `invoices.show`, `payments.show`,
`vehicle-deliveries.show` y el `reports.*` de cada reporte.

| Token | Puede | 403 `FORBIDDEN` en |
|---|---|---|
| `{{token_caja}}` | Factura, recibo, entrega, presupuesto; reportes de ventas y clientes | `reports/profitability?format=pdf` (solo administrador) |
| `{{token_mec}}` | — | `invoices/{id}/pdf`, `budgets/{id}/pdf`, `payments/{id}/receipt` |

---

## FASE 25 — Auditoría

Token: administrador. La auditoría no tiene endpoints de alta: los registros se generan solos al operar sobre precios,
facturación, pagos, compras, usuarios, roles, permisos y datos maestros, y al iniciar o cerrar sesión. Son inmutables.

### 25.1 Generar y consultar un cambio de precio

1. `PATCH {{base_url}}/parts/{{part_id}}` con `{ "sale_price": 1500 }` → `200`.
2. `GET {{base_url}}/audit-logs?auditable_type=part&auditable_id={{part_id}}` → `200`. El primer registro tiene
   `event.value` `updated`, `old_values` `{ "sale_price": … }` (el precio anterior), `new_values`
   `{ "sale_price": 1500 }`, `user` (quién) e `ip_address`. Guardar `data.0.id` → `audit_log_id`.
3. Repetir el mismo `PATCH` sin cambiar el valor: no se agrega un registro nuevo.

### 25.2 Otros eventos

| Acción | Qué buscar en `GET audit-logs` |
|---|---|
| `PATCH parts/{id}/suppliers/{supplier}` con `last_cost` (o recibir una compra) | `auditable_type=part_supplier`, con `part_id` y `supplier_id` en los valores |
| Emitir una factura | `auditable_type=invoice`, `updated`: `status` `draft` → `issued`, `number` asignado |
| Registrar y anular un pago | `auditable_type=payment`: `created` y `updated` (`status` → `cancelled`, motivo) |
| `POST login` con contraseña incorrecta | `event=login_failed`, sin usuario, `new_values.email` |
| `POST login` / `POST logout` | `event=login` / `event=logout`, con el usuario |
| Crear un usuario o cambiar su contraseña | `auditable_type=user`: `password` aparece como `********` |
| `PATCH roles/{id}` con `permissions` | `event=permissions_synced`: lista de permisos antes y después |
| `PATCH users/{id}` con `role` | `event=roles_synced`: roles antes y después |

### 25.3 Filtros, detalle y errores

| Caso | Método / Endpoint | HTTP | Resultado / error_code |
|---|---|---|---|
| Todos (20 por página) | `GET {{base_url}}/audit-logs` | 200 | Del más reciente al más antiguo, `meta.pagination` |
| Por usuario, evento y fecha | `GET {{base_url}}/audit-logs?user_id=1&event=updated&date_from=2026-10-01&date_to=2026-10-31` | 200 | Filtrado |
| Detalle | `GET {{base_url}}/audit-logs/{{audit_log_id}}` | 200 | Además `user_agent` y el email del usuario |
| Evento inválido / tipo con mayúsculas | `GET {{base_url}}/audit-logs?event=x&auditable_type=Part` | 422 | `VALIDATION_ERROR` (`event`, `auditable_type`) |
| Inexistente | `GET {{base_url}}/audit-logs/999999` | 404 | `RESOURCE_NOT_FOUND` |

Tipos (`auditable_type`): `part`, `service`, `budget_item`, `part_supplier`, `invoice`, `invoice_item`, `payment`,
`supplier_payment`, `purchase`, `purchase_item`, `user`, `role`, `permission`, `client`, `vehicle`, `mechanic`,
`invoice_stamp`, `maintenance_plan`.

### 25.4 Permisos

Solo administrador: con `{{token_caja}}`, `{{token_mec}}` o cualquier otro rol del taller, `GET audit-logs` responde
`403 FORBIDDEN`.

---

## Preferencias visuales y logo del sistema (2026-10-24)

### Preferencias del usuario

Cualquier token: cada usuario lee y cambia solo las suyas (sin permiso específico).

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Leer | `GET {{base_url}}/me/preferences` | — | 200 | Las 11 claves; sin cambios, los predeterminados (`theme` `auto`, `theme_primary` `blue`, `navbar_position` `vertical`, `navbar_theme` `dark`, …) |
| En la sesión | `GET {{base_url}}/me` | — | 200 | `data.preferences` con el mismo contenido |
| Cambio parcial | `PATCH {{base_url}}/me/preferences` | `{ "theme": "dark", "theme_primary": "green", "theme_radius": 0.5 }` | 200 | El juego completo; las demás claves no cambian (`theme_radius` vuelve como `"0.5"`) |
| Valor inválido | `PATCH {{base_url}}/me/preferences` | `{ "theme": "sepia" }` | 422 | `VALIDATION_ERROR`, `errors.theme`: "El modo de color debe ser auto, light, dark." |
| Clave inexistente | `PATCH {{base_url}}/me/preferences` | `{ "currency": "USD" }` | 422 | `VALIDATION_ERROR`, `errors.currency` |
| Body vacío | `PATCH {{base_url}}/me/preferences` | `{}` | 422 | `VALIDATION_ERROR`, `errors.preferences` |
| Restaurar | `DELETE {{base_url}}/me/preferences` | — | 200 | Los predeterminados |
| Sin token | `GET {{base_url}}/me/preferences` | — | 401 | `UNAUTHENTICATED` |

Valores: `theme` `auto|light|dark`; `theme_primary` `blue|azure|indigo|purple|pink|red|orange|yellow|lime|green|teal|cyan|inverted`;
`theme_base` `neutral|slate|gray|zinc|stone|mist`; `theme_font` `sans-serif|serif|monospace|comic`; `theme_radius`
`0|0.5|1|1.5|2`; `layout` `default|fluid|boxed`; `navbar_position` `vertical|horizontal|combined`; `navbar_style`
`default|floating`; `navbar_theme` `dark|default|primary`; `navbar` `default|sticky`; `sidebar` `default|folded|folded-hover`.

### Logo del sistema

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Leer (sin token) | `GET {{base_url}}/branding` | — | 200 | `data.logo_url` y `data.logo_black_url` (versión negra): URL pública o `null` |
| Subir PNG transparente | `POST {{base_url}}/system-settings/logo` (token administrador) | form-data `logo` (PNG con fondo transparente) | 200 | `logo_url` recortado a su contenido y `logo_black_url` distinto (todo negro); los archivos anteriores se borran |
| Subir JPG o PNG opaco | `POST {{base_url}}/system-settings/logo` | form-data `logo` (JPG) | 200 | Se guarda tal cual; `logo_black_url` igual a `logo_url` |
| Formato inválido | `POST {{base_url}}/system-settings/logo` | `logo` GIF o WebP | 422 | `VALIDATION_ERROR`: "El logo debe ser una imagen PNG o JPG." |
| Más de 2 MB | `POST {{base_url}}/system-settings/logo` | `logo` de 3 MB | 422 | `VALIDATION_ERROR`: "El logo no debe superar los 2 MB." |
| Quitar | `DELETE {{base_url}}/system-settings/logo` | — | 200 | `data.logo_url` `null` (repetirlo también da 200) |
| Otro rol | `POST`/`DELETE {{base_url}}/system-settings/logo` con `{{token_caja}}` | — | 403 | `FORBIDDEN` |

### Favicon

| Caso | Método / Endpoint | Body | HTTP | Resultado / error_code |
|---|---|---|---|---|
| Leer (sin token) | `GET {{base_url}}/branding` | — | 200 | `data.favicon_url`: URL pública o `null` |
| Subir o reemplazar | `POST {{base_url}}/system-settings/favicon` (token administrador) | form-data `favicon` (PNG o ICO cuadrado, o SVG) | 200 | `data.favicon_url` nuevo; el archivo anterior se borra; el logo no cambia |
| Formato inválido | `POST {{base_url}}/system-settings/favicon` | `favicon` GIF o JPG | 422 | `VALIDATION_ERROR`: "El favicon debe ser una imagen PNG, ICO o SVG." |
| No cuadrado | `POST {{base_url}}/system-settings/favicon` | `favicon` PNG de 64×32 | 422 | "El favicon debe ser cuadrado (la imagen mide 64×32 px)." |
| Muy chico o grande | `POST {{base_url}}/system-settings/favicon` | `favicon` PNG de 8×8 | 422 | "El favicon debe medir entre 16 y 1024 px de lado." |
| Más de 512 KB | `POST {{base_url}}/system-settings/favicon` | `favicon` de 600 KB | 422 | "El favicon no debe superar los 512 KB." |
| SVG con código | `POST {{base_url}}/system-settings/favicon` | SVG con `<script>`, `onload=` o `javascript:` | 422 | "El SVG no puede contener scripts, eventos ni enlaces javascript." |
| Quitar | `DELETE {{base_url}}/system-settings/favicon` | — | 200 | `data.favicon_url` `null` (repetirlo también da 200) |
| Otro rol | `POST`/`DELETE {{base_url}}/system-settings/favicon` con `{{token_caja}}` | — | 403 | `FORBIDDEN` |

Con un logo cargado, los PDF (`budgets/{id}/pdf`, `invoices/{id}/pdf`, `payments/{id}/receipt`,
`vehicle-deliveries/{id}/pdf` y los reportes con `?format=pdf`) muestran la versión negra en lugar del nombre del taller (los demás datos y el RUC siguen), y los
emails a clientes en la cabecera (con `MAIL_MAILER=log`, el HTML del log trae `cid:logo@synctaller` y la parte inline).
El cambio queda en `GET audit-logs?auditable_type=system_setting`.

---

## Errores HTTP genéricos

| Caso | Método / Endpoint | Body | HTTP | error_code |
|---|---|---|---|---|
| Ruta inexistente | `GET {{base_url}}/no-existe` | — | 404 | `RESOURCE_NOT_FOUND` |
| Recurso inexistente | `GET {{base_url}}/work-orders/999999` | — | 404 | `RESOURCE_NOT_FOUND` |
| Método no permitido | `DELETE {{base_url}}/inventory/stocks` | — | 405 | `METHOD_NOT_ALLOWED` |
| Body vacío | `POST {{base_url}}/parts` | `{}` | 422 | `VALIDATION_ERROR` |
| Sin permiso (mecánico) | `POST {{base_url}}/parts` | `{}` | 403 | `FORBIDDEN` (el permiso se evalúa antes que la validación) |

Ningún error de negocio de esta guía debe devolver `500`.

# CLAUDE.md — SyncTaller (Backend)

Instrucciones permanentes para trabajar en este repositorio sin romper lo existente.

| Documento | Para qué |
|---|---|
| `CLAUDE.md` (este) | Reglas operativas, convenciones y reglas de negocio vigentes |
| [`README.md`](README.md) | Instalación, configuración, comandos y estado general |
| [`docs/handoff.md`](docs/handoff.md) | Historia y decisiones de diseño por fase (FASES 9–20), roadmap original y **deuda técnica conocida (sección 14)** |
| [`docs/testing/guia-postman.md`](docs/testing/guia-postman.md) | Pruebas manuales de la API, encadenadas por fase |

Si una regla de este archivo contradice al código, **el código manda**: registrar la discrepancia (handoff, sección 14)
y avisar, en lugar de "corregir" por cuenta propia.

---

## 1. Estado actual (verificado el 2026-10-29)

* **Backend/API:** Laravel 13.38 (PHP ^8.3, spatie/laravel-permission 8, PHPUnit 12), roadmap original completo (FASES 10–20), compras (FASE 21), pagos a
  proveedores (FASE 22), notificaciones (FASE 23), exportación y comprobantes imprimibles (FASE 24) y auditoría
  (FASE 25), más preferencias visuales por usuario y logo del sistema (6.21) y el catálogo de ítems de diagnóstico
  (6.3), y el módulo de aseguradoras en curso (M1–M9, P10 y M11, 6.22–6.30). 282 rutas API, 464 tests en verde.
* **Nombre:** el proyecto se llamaba **Presupuestador**; desde el 2026-10-21 es **SyncTaller** (repos
  `synctaller-backend` y `synctaller-frontend`, base de datos `synctaller`; la conexión es `mysql`).
* **Frontend:** **no está en este repositorio**, sino en `../synctaller-frontend` (React 19 + TypeScript + Vite +
  Tabler; ver su `CLAUDE.md` y `docs/plan-desarrollo.md`). CORS y Sanctum están configurados para
  `http://localhost:5173`; CORS expone `Content-Disposition` (nombre de las descargas). `package.json` y `resources/` de este repositorio son el scaffold de Laravel (Vite +
  Tailwind) sin uso, salvo las vistas de email (`resources/views/mail`) y de PDF (`resources/views/pdf`).
* **Pendiente (sin diseño ni fase asignada):** notificaciones por WhatsApp.

---

## 2. Metodología de trabajo

Antes de implementar cualquier cambio:

1. Inspeccionar el código existente relacionado (Service, Request, Resource, Enum, excepción, rutas, permisos, tests).
2. Identificar las reglas de negocio y los módulos afectados (ver sección 6 y la "regla de oro" del handoff, sección 13).
3. Proponer el cambio mínimo y explicar su motivo. Las decisiones de negocio se consultan, no se suponen.
4. Implementar solo el alcance autorizado.
5. Ejecutar los tests pertinentes y la suite completa antes de dar algo por terminado.
6. Verificar que no cambien contratos, permisos, `error_code` ni códigos HTTP existentes.
7. Informar archivos modificados, tests ejecutados y resultados.
8. No declarar una fase cerrada sin evidencia (código + tests).

En fases nuevas: primero diseño funcional y de datos (con las preguntas de negocio), después código.

### Git

* Nunca trabajar ni commitear en `main`. Una rama por tarea desde `origin/main`: `feature/…`, `fix/…`, `docs/…`, `test/…`.
* Commit y push solo cuando el usuario lo pide. El merge a `main` lo hace el usuario (salvo pedido explícito).
* Mensajes de commit en español, describiendo el porqué.

### Documentación por cambio

Cada fase o cambio de API actualiza, en la misma rama: este archivo (reglas), `docs/handoff.md` (decisiones e
historia), `README.md` (si cambia instalación, módulos o estado) y `docs/testing/guia-postman.md` (requests, bodies,
resultados esperados y casos de error).

---

## 3. Convenciones obligatorias

* La lógica de negocio va en **Services**; los Controllers solo reciben, autorizan, llaman al Service y devuelven
  un Resource con `ApiResponse`.
* Reutilizar Requests, Resources, Enums y excepciones existentes antes de crear nuevos.
* Mantener el **contrato público de la API** (formato de respuesta, nombres de campos, `error_code`, códigos HTTP).
* Estados y tipos formales siempre con **Enum** (`BudgetStatus::APPROVED`, nunca `'approved'`). Ojo: comparar enums
  con enums (`in_array($x, [Enum::A], true)`), nunca contra strings.
* Errores de negocio: excepción de dominio con `error_code` y `422` (o `409` en conflictos). Nunca `500` ni trazas.
* No cambiar rutas, permisos ni contratos sin justificación funcional.
* No refactorizar código estable por estilo. No modificar módulos terminados sin necesidad demostrable.
* No agregar dependencias sin evaluar necesidad y compatibilidad.
* No cambiar el esquema ni crear migraciones por iniciativa propia: proponerlas.
* **Las migraciones no llaman a código de la aplicación** (Services/Models): solo estructura.
* **Nombres de índices y claves foráneas: hasta 64 caracteres** (límite de MySQL). Los tests usan SQLite, que no tiene
  ese límite: si el nombre automático (`tabla_columna_foreign`) es largo, darle uno propio (`indexName:`). Probar la
  migración nueva en MySQL antes de darla por terminada.
* Dinero y cantidades con **BCMath** sobre strings (`App\Support\Decimal::round`), nunca `float`.
* Operaciones sobre varios registros en `DB::transaction`; concurrencia con `lockForUpdate`.
  Orden de bloqueo: OT → línea → `part_stocks`; OT → recepción (recálculo de estado); factura → pago;
  recepción → entregas; compra → `part_stocks`; compra → pago a proveedor; recepción → siniestro; presupuesto → autorización de la
  aseguradora → documentos del siniestro; presupuesto u OT → excepción interna; siniestro → OT → reparto → excepción; siniestro → OT → reparto → factura; factura → nota de crédito;
  documento → serie de códigos (siempre última).
* **Códigos internos** (`BUD-`, `OT-`, `COM-`, `REP-`, `SRV-`, `DIA-`, `ASE-`, `SIN-`): salen de `DocumentSequenceService::create()`
  (serie en `App\Enums\DocumentSeries`, contador en `document_sequences`), nunca de `max(id) + 1`. El contador de la
  serie se bloquea dentro de la transacción del alta (un alta fallida no consume el número), se saltan los códigos ya
  usados y un repetido igual responde `409 DOCUMENT_CODE_CONFLICT`. Puede haber huecos: no son documentos fiscales.
  La fila de cada serie se crea en el primer uso con el máximo id + 1. Una serie nueva se agrega al enum.
* Documentos emitidos (cierre económico, factura emitida, pago, entrega, movimiento de inventario, compra recibida,
  pago a proveedor) son
  **inmutables a nivel de modelo**: solo se anulan o se corrigen con otro registro.
* Rutas anidadas con `scopeBindings()` (grupo global en `routes/api.php`); si el parámetro hijo **no** es una relación
  del padre, usar `->withoutScopedBindings()` en esa ruta.
* Actualizaciones parciales con `PATCH`.
* Resources: `XResource` para listados, `ShowXResource` para detalle. `$this->when()` no se resuelve dentro de
  colecciones anidadas (`->map()`): agregar claves condicionales a mano.
* Mensajes al usuario en español; código, clases, campos y `error_code` en inglés.

### Permisos

* Cada Controller implementa `HasMiddleware` con `permission:<recurso>.<acción>` por método.
* El nombre del permiso del middleware debe existir **exactamente** en `RolesAndPermissionsSeeder`. El rol
  `administrador` pasa todo por `Gate::before` (`AppServiceProvider`), así que un nombre mal escrito no se nota con el
  admin: probar con un rol propio (ver `tests/Feature/Access`).
* Roles del taller en `RolesAndPermissionsSeeder::workshopRoles()`; `WorkshopRolesTest` verifica que todos sus
  permisos existan.

### Entorno (Windows / Laragon)

* Tests: `php artisan test` o `vendor/bin/phpunit` (SQLite en archivo, `database/testing.sqlite`: migra y siembra roles y
  permisos una vez por ejecución, cada test en una transacción; no sembrarlos en `setUp`). Si `pdo_sqlite` está deshabilitado:
  `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit`. Los tests no necesitan red:
  `phpunit.xml` fija `EMAIL_DNS_CHECK=false` (sin consulta DNS de emails) y `uncompromised()` se simula con `Http::fake`.
  También desactiva la sincronización a disco de SQLite (`DB_SQLITE_SYNCHRONOUS`, `DB_SQLITE_JOURNAL_MODE`, conexión
  `sqlite` de `config/database.php`).
* No editar archivos con `Get-Content`/`Set-Content` de PowerShell 5.1: corrompen UTF-8 y agregan BOM. Usar las
  herramientas de edición.
* Servidor web local: **Nginx** de Laragon (puerto 80, PHP por `php-cgi`), no Apache. Con Apache (PHP como módulo
  multihilo) algunos requests concurrentes corrían sin el `.env` y fallaban con `APP_ENV=production` (el frontend los
  veía como CORS; `docs/handoff.md`, sección 14, #12). No usar `config:cache` en desarrollo: los tests correrían
  contra MySQL.
* No ejecutar `migrate:fresh`, `db:wipe` ni seeders de datos de ejemplo sobre la base de desarrollo sin pedido
  explícito (ver README, sección de base de datos).
* **Seeders de ejemplo:** un seeder por módulo, registrados en `DatabaseSeeder`. El flujo del taller sale de
  `database/seeders/ExampleScenarios.php` (un escenario por recepción y su estado final); cada seeder recorre los
  escenarios y hace su parte **por los Services** (nunca `create()` directo de documentos), con el usuario del rol que
  corresponde y el reloj en la fecha del evento (`ExampleScenarios::travel`). Reglas:
  * Idempotentes: cada seeder omite lo que ya existe (`DatabaseSeederTest` siembra dos veces).
  * El kardex guarda el saldo acumulado y se lista por orden de carga: los movimientos de stock se siembran en orden
    cronológico (compras antes de los escenarios, ajustes al final).
  * Un estado nuevo en un enum necesita su escenario y su verificación en `DatabaseSeederTest`.
  * Los usuarios nuevos usan `TEST_PASSWORD`; el administrador principal, `ADMIN_PASSWORD`.
  * No envían emails reales: `NotificationSeeder` usa el mailer `array`.
* Notificaciones: los emails salen por cola (`QUEUE_CONNECTION=database`) y los recordatorios por el scheduler; sin
  `php artisan queue:work` y `php artisan schedule:run` (cada minuto) los avisos quedan `pending` o no se generan.
  En los tests la cola es `sync` y el mail `array` (`Mail::fake()` para verificar envíos).

---

## 4. Arquitectura

```text
Controller (HasMiddleware) → Form Request → Service → Model/Eloquent → API Resource → ApiResponse
```

| Carpeta | Contenido |
|---|---|
| `app/Http/Controllers/Api` | 58 controllers |
| `app/Http/Requests` | Validación (mensajes en español) |
| `app/Services` | Reglas de negocio (45 services, incluido `AuditLogger`) |
| `app/Jobs`, `app/Mail`, `app/Notifications`, `app/Console/Commands` | Envío de avisos (FASE 23) |
| `app/Models` | Modelos Eloquent (muchos con `SoftDeletes`) |
| `app/Http/Resources` | Contrato JSON |
| `app/Enums` | Estados y tipos |
| `app/Exceptions` | `AppException` y una subclase por módulo |
| `app/Support/Decimal.php` | Redondeo BCMath |
| `app/Traits/ApiResponse.php` | `successResponse` / `errorResponse` |
| `app/Traits/FileResponse.php` | Descarga de archivos generados (PDF, CSV) y de documentos subidos (`storedFileResponse`: adjunto y `nosniff`) |
| `app/Traits/Auditable.php` | Registro automático de cambios en `audit_logs` |
| `bootstrap/app.php` | Mapeo de excepciones a JSON |
| `config/invoice.php`, `maintenance.php`, `notifications.php`, `company.php` | Parámetros de negocio (`invoice.default_tax_rate` es el IVA de presupuestos y facturas; `company` son los datos del taller para los PDF) |

### Contrato de respuesta

```json
{ "success": true, "status": 200, "message": "...", "data": {}, "errors": null, "meta": null, "timestamp": "..." }
{ "success": false, "status": 422, "message": "...", "data": null, "errors": null, "error_code": "..." }
```

* Listados: `meta.pagination` (`total`, `perPage`, `currentPage`, `lastPage`).
* `bootstrap/app.php` mapea: 400 `BAD_REQUEST`, 401 `UNAUTHENTICATED`, 403 `FORBIDDEN`, 404 `RESOURCE_NOT_FOUND`,
  405 `METHOD_NOT_ALLOWED`, 406 `NOT_ACCEPTABLE`, 415 `UNSUPPORTED_MEDIA_TYPE`, 422 `VALIDATION_ERROR`,
  429 `TOO_MANY_REQUESTS`, 500 `INTERNAL_SERVER_ERROR`, 503 `SERVICE_UNAVAILABLE`, y cualquier `AppException` con su
  propio `status` y `error_code`. Solo si el request espera JSON: el middleware global `EnsureJsonResponse` fija
  `Accept: application/json` en toda ruta `api/*` (también 404/405), así que la API nunca responde HTML.
* Excepciones de dominio: `BudgetException`, `WorkOrderException`, `WorkOrderPartException`, `PartException`,
  `SupplierException`, `InventoryException`, `ServiceException`, `InvoiceException`, `PaymentException`,
  `VehicleDeliveryException`, `MaintenanceException`, `ReceptionException`, `PurchaseException`,
  `SupplierPaymentException`, `NotificationException`, `AuditException`, `VehicleCatalogException`, `ClientException`, `InsurerException`, `ClaimException`, `InsurerAuthorizationException`, `InternalExceptionException`, `AllocationException` (todas extienden `AppException(message, status, errorCode, errors)`).

### Autenticación

Sanctum con tokens Bearer: `POST /api/login` (público) devuelve `data.token`; todo lo demás está bajo
`auth:sanctum`. `POST /api/logout` revoca el token actual. Los tokens no expiran (`sanctum.expiration = null`).
`GET /api/me` (sin permiso específico) devuelve `data.user` (`UserResource`, como el login) y `data.permissions` (nombres
de los permisos, ordenados; el administrador recibe todos los del guard `api` por el `Gate::before`), más
`data.mechanic` (`id`, `employee_code`, `full_name` de la ficha de mecánico vinculada, o `null`) y `data.preferences`
(preferencias visuales completas, 6.21). Lo usa el frontend para hidratar la sesión y adaptar menús y botones.
`GET /api/branding` (logo del sistema) es público, como el login.

---

## 5. Roles

| Rol | Función | Accesos clave |
|---|---|---|
| `administrador` | Todo (`Gate::before`) | Exclusivo: usuarios/roles/permisos, auditoría (`audit-logs.*`), logo del sistema (`system-settings.update`), costos y margen (`work-orders.view-economics`), liquidar comisiones, anular entregas, timbrados, ABM de planes de mantenimiento, reporte de rentabilidad |
| `mechanic` | Ejecuta la OT | Diagnóstico (consulta y usa el catálogo de ítems), iniciar/pausar/completar OT e ítems, consumir/devolver repuestos, sus horas y comisiones, historial, estado de mantenimiento, dashboard (bloque taller) |
| `recepcionista` | Atiende al cliente | Clientes, vehículos, recepciones, checklist, presupuestos completos, registrar entregas, alertas de mantenimiento, reporte de presupuestos, avisos a clientes (consultar y reenviar), ver repuestos de la OT y registrar la respuesta del cliente a los extras, aseguradoras completas (alta, edición, estado, baja y contactos), siniestros (alta, edición y cancelación, con sus documentos: subir, listar y descargar), autorizaciones de la aseguradora (registrar y consultar) y re-aceptaciones del dueño y consulta del reparto de la OT |
| `jefe-taller` | Organiza el trabajo | OT completas, ítems, repuestos en OT (incluida la respuesta del cliente a los extras), asignación y horas de todos, diagnóstico y su catálogo de ítems, intervalos de mantenimiento por vehículo, reporte de productividad, consulta de compras, pagos a proveedores y cuentas por pagar, consulta de aseguradoras y siniestros (alta, edición y cancelación, con sus documentos: subir, listar y descargar), autorizaciones de la aseguradora (registrar y consultar) y excepciones internas (otorgar y consultar) y consulta del reparto de la OT |
| `cajero` | Cobra y paga | Facturas, pagos, resumen de caja, reportes de ventas, clientes, cuentas por cobrar y siniestros, consulta de compras, pagos a proveedores completos (registrar, anular, resumen de egresos), cuentas por pagar, avisos a clientes (consultar y reenviar) y consulta de aseguradoras, siniestros (con la descarga de sus documentos), autorizaciones de la aseguradora y excepciones internas, y el reparto de la OT completo (generar, editar, confirmar y descartar el borrador), y notas de crédito externas (registrar y anular) |
| `deposito` | Stock | Repuestos, proveedores, inventario, compras completas, repuestos en OT, reporte de inventario, consulta de pagos a proveedores y cuentas por pagar |

El rol del mecánico se llama `mechanic` (no `mecanico`). El seeder crea usuarios de cada rol (README, "Datos de prueba").
La bandeja interna (`me/notifications`) y las preferencias (`me/preferences`) no usan permisos: cada usuario ve solo
lo suyo.

---

## 6. Reglas de negocio vigentes

Fuente: Services y tests. El detalle de diseño y su historia por fase está en el handoff (secciones 2.24–2.34).

### 6.1 Clientes, vehículos y datos maestros

* Al eliminar (soft delete) clientes, usuarios, marcas, modelos, vehículos y ítems de checklist se agrega
  `//deleted_<timestamp>` a sus campos únicos para liberarlos.
* Datos maestros ya usados no se eliminan (decisión del 2026-10-22; los documentos viejos deben seguir mostrándolos
  intactos), todos `409`: mecánico con OT, asignaciones o diagnósticos (`MECHANIC_IN_USE`: se pasa a inactivo); vehículo
  con recepciones (`VEHICLE_IN_USE`: si cambió de dueño, se edita su cliente); ítem de checklist usado en recepciones
  (`CHECKLIST_ITEM_IN_USE`: se desactiva con `is_active`, y los inactivos no entran en checklists nuevos); servicio en
  presupuestos o en un plan de mantenimiento (`SERVICE_IN_USE`); cliente con vehículos vigentes, recepciones o facturas
  (`CLIENT_IN_USE`, `ClientService::delete`, decisión del 2026-10-29) o vinculado a una aseguradora vigente
  (`CLIENT_LINKED_TO_INSURER`; primero se da de baja la aseguradora). Un servicio usado en presupuestos no cambia de
  nombre (`SERVICE_NAME_NOT_EDITABLE`, 422); precio, horas, descripción y categoría sí.
* Cliente: `document_number` único (letras, números, guiones), `email` único `email:rfc,dns` (el DNS se omite
  con `EMAIL_DNS_CHECK=false`, ver `config/validation.php`), teléfono `+?[1-9]\d{7,14}`.
* **Persona o empresa** (`clients.kind`, `ClientKind`: `person|company`; M1, decisión del 2026-10-29). Sin `kind`, el
  alta es la de siempre (persona). Una empresa lleva `business_name` (mismos caracteres que el nombre de la factura) y
  no lleva `first_name`/`last_name` (`prohibited`); una persona, al revés. `address` es opcional para los dos. El tipo
  cambia solo si el cliente no tiene recepciones ni facturas (`CLIENT_KIND_NOT_EDITABLE`, 422); al cambiar, se vacían
  los campos del tipo anterior. La lógica de alta, edición y baja está en `ClientService`.
* **Nombre del cliente:** siempre `Client::display_name` ("nombre apellido" de una persona, idéntico al texto anterior;
  razón social de una empresa), nunca concatenando nombre y apellido. Lo usan la factura (`customer_name`), los PDF, los
  reportes, el mantenimiento, el historial y los Resources de recepción, vehículo, entrega, diagnóstico y avisos (las
  claves no cambiaron). El saludo de los avisos usa `greeting_name`: el nombre de pila de una persona (como antes) o la
  razón social. Las búsquedas de cliente (`search` y los filtros `first_name` y `client_first_name`) también buscan por
  razón social. **No crear empresas en producción hasta adaptar el frontend** (tipos que admiten `null` en nombre y
  apellido, y `display_name`). El dígito verificador del RUC no se valida (pendiente V10).
* Vehículo: `plate` obligatoria salvo `no_plate = true` (entonces `chassis` obligatorio); placa y chasis únicos;
  `year` ≤ año actual; color, combustible y transmisión por enum. Rutas anidadas `clients/{client}/vehicles` (index,
  store, show; el `client_id` sale de la URL); editar y eliminar solo en `vehicles/{vehicle}`. La tabla es `vehicles`
  (el vehículo es una entidad propia; su dueño puede cambiar).
* Marca o modelo con vehículos vigentes no se elimina (`BRAND_HAS_VEHICLES`, `VEHICLE_MODEL_HAS_VEHICLES`, 409), ni
  una marca con modelos vigentes (`BRAND_HAS_MODELS`, 409); ver `VehicleCatalogService`. `GET vehicle-models` filtra
  por `brand_id`. El listado de clientes incluye `document_number`.
* Ediciones: los opcionales que el alta acepta como `null` también se pueden vaciar al editar (cliente, vehículo,
  mecánico). `hour_cost` y `commission_percentage` del mecánico son opcionales pero no vacíos (columnas `NOT NULL
  DEFAULT 0`): sin enviarlos quedan en 0; `null` → 422.
* Usuario: contraseña ≥ 12 con mayúsculas, minúsculas, números, símbolos y `uncompromised()`.

### 6.2 Recepción y checklist

* El vehículo debe pertenecer al cliente; no puede haber otra recepción **abierta** del vehículo:
  `ReceptionStatus::openStatuses()` = todo salvo `delivered` y `cancelled` (`pending`, `in_progress`, `repair`,
  `completed`; mismo criterio que "vehículos en taller" del dashboard). Al editar, solo se valida si la recepción
  editada está abierta.
* Al menos una categoría de servicio; el checklist se genera con los ítems de esas categorías (sin repetir).
* Cambiar categorías solo con la recepción `pending` (regenera el checklist).
* Recepción "procesada" (`isProcessed()` = todo salvo `pending`): no se cambian categorías
  (`RECEPTION_CATEGORIES_NOT_EDITABLE`) ni checklist (`RECEPTION_CHECKLIST_NOT_EDITABLE`), no se elimina
  (`RECEPTION_NOT_DELETABLE`); todos `422`.
* Valor del checklist según el tipo del ítem: `boolean` → booleano, `number` → numérico, `text` → `good|bad|na`.
  El estado del checklist se calcula (`pending`, `in_progress`, `completed`).
* **Estado derivado del flujo** (`ReceptionStatusService::sync()`, nunca se asigna a mano salvo entrega y cancelación):
  alguna OT `in_progress|paused` → `repair` ("En reparación"); alguna OT `pending` → `in_progress` ("En proceso");
  OT completadas (resto canceladas) → `completed` ("Terminado"); sin OT vigentes → `in_progress` si tiene presupuesto,
  si no `pending`. Se recalcula al crear/eliminar presupuestos y al crear, iniciar, pausar, reanudar, completar o
  cancelar OT. `delivered` y `cancelled` no se recalculan. La migración 000045 repite la regla en SQL.
* Cancelar (`POST receptions/{reception}/cancel`, motivo obligatorio, permiso `receptions.cancel`): solo sin OT vigentes
  (`RECEPTION_HAS_ACTIVE_WORK_ORDERS`); cerrada → `RECEPTION_NOT_CANCELLABLE`. Guarda `cancelled_at`, `cancelled_by` y
  `cancellation_reason`. Cancela además sus presupuestos `draft|sent` (el aprobado sin OT queda como está).
* Recepción cerrada (`delivered|cancelled`): no admite diagnósticos, presupuestos ni OT nuevos, y nada de lo existente
  se modifica: datos y fotos de la recepción, diagnósticos (con sus ítems y fotos) ni presupuestos (salvo cancelarlos):
  `RECEPTION_CLOSED` (`ReceptionStatusService::ensureEditable()`; decisión del 2026-10-22).
* Fotos en el disco `public` (`receptions/`), con scoped binding a la recepción.
* Contrato: fechas `Y-m-d` y `mileage` entero (sin formatear); el detalle trae `check_list` (`id`, `status`) para
  `reception-check-lists/{id}`, y el vehículo con `chassis`, `brand`, `model` y `color` (`value`/`label`).
  `fuel_level` es opcional pero no vacío (`NOT NULL DEFAULT empty`): `null` → 422 en alta y edición.

### 6.3 Diagnóstico

* `mechanic_id` referencia `mechanics` (no `users`). Nace `pending`.
* Ítems con `severity` y `status` por enum; fotos en `diagnostic-items/{id}`; eliminar el ítem elimina sus fotos.
* Fotos de diagnóstico con permisos `diagnostic-item-photos.*`.
* Editar vacía `mechanic_id`, `customer_complaint`, `diagnosis` y `recommendation` con `null`. `priority` (diagnóstico)
  y `severity`/`status` (ítem) son opcionales pero no vacíos (columnas `NOT NULL` con default): `null` → 422.
* Diagnóstico `completed|cancelled`: no se agregan, editan ni eliminan ítems ni fotos (`DIAGNOSTIC_NOT_EDITABLE`); el
  diagnóstico sí se edita, y volverlo a `in_progress` permite corregirlos (`DiagnosticService`).
* El listado se ordena por `diagnosed_at` descendente (antes por fecha de carga).
* **Catálogo de ítems de diagnóstico** (`diagnostic-item-templates`, `DIA-000001`; decisión del 2026-10-24): título único
  entre los vigentes, categoría de servicio opcional, severidad sugerida, requiere reparación / reemplazo, tiempo
  estimado y recomendación (sin costo: el precio va en el presupuesto). Estado `active|inactive`
  (`POST …/activate|deactivate`; repetir → `DIAGNOSTIC_ITEM_TEMPLATE_INVALID_STATUS_TRANSITION`, 422). Mismas reglas de
  texto que el ítem del diagnóstico. Permisos `diagnostic-item-templates.index|show` (mecánico) y además
  `store|update|destroy|change-status` (administrador y jefe de taller).
* **El ítem del diagnóstico se elige siempre del catálogo**, como el repuesto de una línea de compra (decisión del
  2026-10-24): `POST diagnostic-items` exige `diagnostic_item_template_id` (vigente; inactivo →
  `DIAGNOSTIC_ITEM_TEMPLATE_INACTIVE`, 422) y no acepta `title` (`prohibited`): el título es el del catálogo. Los demás
  campos no enviados (o `null`) se **copian** del catálogo y `status` arranca `pending`
  (`DiagnosticItemTemplateService::prepareDiagnosticItem`). Al editar, `title` y `diagnostic_item_template_id` son
  `prohibited`: para cambiar de ítem se elimina y se agrega otro. La columna es `NOT NULL` (migración `000055`, que
  pasó los ítems libres existentes al catálogo).
* Editar o eliminar la entrada del catálogo después no cambia los ítems (guardan su copia, título incluido): conservan
  el vínculo (`template`: `id`, `code`, `title`, `deleted`) y la baja del catálogo es lógica aunque esté en uso (la
  clave foránea es `restrictOnDelete`).

### 6.4 Presupuestos

* Código `BUD-…`. Estados: `draft → sent → approved`; `sent → rejected → draft` (reabrir, borra `approved_at`);
  cancelar desde `draft` o `sent`.
* **Respuesta del cliente** (`budget_client_acceptances`, decisión del 2026-10-28): cada `approve` y `reject` crea una
  fila inmutable (`BUDGET_CLIENT_ACCEPTANCE_IMMUTABLE`, 409) con `kind` (`ClientAcceptanceKind`: `approval|rejection`, y
  `re_acceptance` desde M7, 6.26), quién lo registró (`decided_by`), `decided_at`, `channel` (`ClientDecisionChannel`),
  `contact`, `notes` y `owner_amount` (importe sin IVA a cargo del cliente: en un particular, el subtotal; con líneas
  `insurer`, su parte, 6.26). El body es **opcional** (sin body,
  el contrato es el de siempre; el motivo del rechazo también es opcional). Reabrir no borra el historial. El detalle
  del presupuesto trae `client_acceptances` en orden. Los presupuestos anteriores a E5 no tienen filas. `Budget` usa
  `Auditable`.
* Solo `draft` es editable (cabecera e ítems): `BUDGET_NOT_EDITABLE`. Enviar y aprobar exigen ítems
  (`BUDGET_WITHOUT_ITEMS`). Transición inválida: `BUDGET_NOT_SENDABLE|APPROVABLE|REJECTABLE|CANCELLABLE|REOPENABLE`.
* Enviar exige los diagnósticos de la recepción `completed|cancelled` (`BUDGET_DIAGNOSTICS_PENDING`, con los números
  pendientes); el borrador se arma sin esa condición. Con la recepción cerrada solo se puede cancelar: editar, enviar,
  aprobar, rechazar, reabrir o eliminar → `RECEPTION_CLOSED` (6.2).
* Totales con BCMath (`BudgetService::itemTotal()` y `recalculateBudget()`), redondeo half-up a 2 decimales: ítem =
  cantidad × precio; `subtotal` = suma de ítems; `tax` = subtotal × `config('invoice.default_tax_rate')` (10) / 100;
  precios sin IVA.
* Importes del presupuesto y sus ítems en `decimal(14,2)` (hasta 999.999.999.999,99; `BudgetService::MAX_AMOUNT`);
  `quantity` en `(10,2)`. Topes de validación en `unit_price` y `quantity` (422 `VALIDATION_ERROR`). Si el total del
  ítem o del presupuesto, con el IVA, no entra: `BUDGET_AMOUNT_OUT_OF_RANGE` (422), y el ítem no se guarda (el alta y
  la edición del ítem corren en una transacción con el recálculo).
* Ítems `labor | service | part`. `part_id` solo en `part` (repuesto `inactive` → `PART_NOT_USABLE`; unidades enteras
  → `INVENTORY_FRACTIONAL_QUANTITY_NOT_ALLOWED`). `service_id` solo en `service` (sin `unit_price` toma
  `standard_price`; inactivo → `SERVICE_NOT_USABLE`). `estimated_hours`: service = catálogo × cantidad si no se informa;
  labor opcional; part prohibido.
* **Responsable de la línea** (`budget_items.responsibility`, `BudgetItemResponsibility`: `owner|insurer`; M5, decisiones
  del 2026-10-29):
  * sin enviarlo, la línea queda a cargo del dueño, también si hay siniestro: lo que paga la aseguradora se marca a
    propósito;
  * `insurer` solo si la recepción tiene un siniestro **abierto** (`BUDGET_ITEM_INSURER_WITHOUT_CLAIM`, 422,
    `BudgetService::ensureResponsibilityAllowed`);
  * se edita solo en borrador, como el resto del ítem;
  * al cancelar el siniestro, las líneas `insurer` de los presupuestos **en borrador** vuelven a `owner`. Un presupuesto
    enviado o aprobado no se toca: se rechaza, se reabre y el cliente vuelve a aprobar.

  No cambia totales, IVA, estados, OT ni factura.
* Contrato: el detalle trae cada ítem con `part` / `service` (`id`, `code`, `name`) y la recepción con marca y modelo
  del vehículo. Editar un ítem acepta los mismos caracteres que el alta y vacía `notes` con `null`.

### 6.5 Orden de trabajo

* Nace de un presupuesto `approved` con ítems (`WORK_ORDER_BUDGET_NOT_APPROVED`, `WORK_ORDER_BUDGET_WITHOUT_ITEMS`);
  una por presupuesto (`WORK_ORDER_ALREADY_EXISTS`). Código `OT-000001` (serie con bloqueo, ver sección 3).
* **Con líneas `insurer`** (M7, 6.26; después de las validaciones anteriores): con el siniestro abierto exige la
  autorización vigente (`WORK_ORDER_INSURER_AUTHORIZATION_REQUIRED`, 422) salvo la excepción
  `start_without_authorization` del presupuesto (`internal_exception_id` opcional en `POST work-orders`, que la consume);
  siempre exige que el importe aceptado cubra la parte actual del dueño (`WORK_ORDER_CLIENT_ACCEPTANCE_OUTDATED`, 422:
  se registra una re-aceptación; la excepción no la reemplaza). Una excepción de otro presupuesto o innecesaria →
  `INTERNAL_EXCEPTION_NOT_APPLICABLE` (422); ya usada → `INTERNAL_EXCEPTION_ALREADY_CONSUMED` (409). Iniciar, pausar y
  completar no cambian. Sin líneas `insurer`, nada cambia.
* Un `WorkOrderItem` por `BudgetItem`. `WorkOrderItem` es ejecución, no inventario ni precios (los precios están en
  `BudgetItem`; los repuestos usados en `WorkOrderPart`).
* Estados: `pending → in_progress ⇄ paused`, `in_progress → completed`, `pending → cancelled`. Nunca vuelve atrás.
  No hay estado de entrega: la OT entregada sigue `completed`.
* Editar (`mechanic_id`, `notes`) solo en `pending` (`WORK_ORDER_NOT_EDITABLE`); cambiar el responsable reasigna sus
  trabajos al 100%.
* Cancelar solo en `pending`: cancela ítems y líneas de repuestos abiertas (libera reservas).
* Completar: sin ítems `pending|in_progress` (`WORK_ORDER_HAS_PENDING_ITEMS`), sin repuestos `pending|reserved`
  (`WORK_ORDER_HAS_UNRESOLVED_PARTS`); en la misma transacción congela el cierre económico (6.9) y registra
  mantenimientos (6.13).
* Ítems: iniciar/completar/cancelar solo con la OT `in_progress`; iniciar desde `pending`, completar desde
  `in_progress`, cancelar solo `pending` (cancela sus líneas de repuestos). Permisos `work-order-items.*`. La respuesta
  es la OT completa.
* Contrato: listado y detalle traen la recepción con marca y modelo del vehículo; cada asignación de mecánico trae
  `mechanic.user_id` (el mecánico solo carga sus propias horas).
* El responsable de la OT y el mecánico del diagnóstico se cargan con `withTrashed()`: eliminar un mecánico no borra su
  nombre de la OT, el historial ni el diagnóstico (como ya pasaba con asignaciones y comisiones).

### 6.6 Catálogo de repuestos e inventario

* `Part` (código `REP-…`, SKU único, unidad por enum, estados `active | inactive | discontinued`), `PartCategory`,
  `Supplier` y pivote `part_supplier` (`last_cost` se actualiza con las compras).
* Un depósito (`DEP-01`). `part_stocks` (on_hand, reserved, average_cost) cambia solo por
  `InventoryService::applyMovement()` con `lockForUpdate`.
* Movimientos inmutables (`INVENTORY_MOVEMENT_IMMUTABLE`) con signo; `balance_after` acumulado. Costo promedio
  ponderado (4 decimales) recalculado solo en entradas. Sin stock negativo: salidas contra disponible
  (`INSUFFICIENT_STOCK`). Ajuste = conteo físico (`INVENTORY_NO_DIFFERENCE`, `STOCK_BELOW_RESERVED`).
* Descontinuado: sin entradas (`PART_DISCONTINUED_NO_ENTRY`). `unit|set|kit` sin fracciones. Repuesto con stock no se
  elimina (`PART_HAS_STOCK`). Cantidades de inventario `decimal(12,3)`.
* Entrada manual (`POST inventory/entries`) solo `initial` (una vez por repuesto); las compras entran solo por el
  módulo de compras (6.15).
* Ediciones de repuesto (`sku`, `part_number`, `description`, `brand`), proveedor (`tax_id`, `contact_name`, `phone`,
  `email`, `address`, `notes`) y vínculo repuesto–proveedor (`supplier_part_code`, `last_cost`) aceptan `null` para
  vaciar los opcionales. `minimum_stock` es opcional pero no vacío (`NOT NULL DEFAULT 0`): sin enviarlo queda en 0;
  `null` → 422 en alta y edición.

### 6.7 Repuestos en la OT (`WorkOrderPart`)

* Al crear la OT, una línea por `BudgetItem` `part` con `part_id`; intenta reservar (sin stock queda `pending`).
* Ciclo: `pending → reserved → consumed → returned` (si el neto llega a 0); `pending|reserved → cancelled`.
* Agregar/editar/reservar/cancelar con OT `pending|in_progress|paused`; consumir/devolver solo `in_progress`.
  OT cerrada: `WORK_ORDER_PARTS_NOT_EDITABLE`. Un consumo por línea; lo usado puede diferir de lo reservado.
* `unit_cost` sale del movimiento de consumo; `unit_price` del `BudgetItem` o del `sale_price`. Totales sobre la
  cantidad neta (consumida − devuelta), no se persisten.
* **Extras** (`POST work-orders/{id}/parts`, `work_order_item_id` `null`, decisión del 2026-10-23; resuelve la deuda
  #11). Nacen `awaiting_approval`: no reservan ni se consumen hasta registrar la respuesta del cliente
  (`WORK_ORDER_PART_NOT_APPROVED`).
  * `POST …/parts/{part}/approve` pasa la línea a `pending` e intenta reservar. `POST …/parts/{part}/reject` la deja
    `cancelled`. Ambos con permiso `work-order-parts.approve` (administrador, recepcionista, jefe-taller) y la OT
    `pending|in_progress|paused`.
  * Se guardan `client_decision` (`ClientDecision`), `decided_at`, `decided_by` (quien lo registró),
    `decision_channel` (`ClientDecisionChannel`: `phone|whatsapp|in_person|email|other`, obligatorio),
    `decision_contact` y `decision_notes` (obligatorio al rechazar).
  * Lo aprobado no cambia: cantidad y precio → `WORK_ORDER_PART_APPROVED_NOT_EDITABLE` (las notas sí; para otro
    número se cancela y se carga otro extra). No se consume más de lo aprobado (`WORK_ORDER_PART_EXCEEDS_APPROVED`);
    las líneas presupuestadas sí pueden usar más de lo reservado.
  * `awaiting_approval` está en `openStatuses()`: la OT no se completa (`WORK_ORDER_HAS_UNRESOLVED_PARTS`) y por lo
    tanto no se factura con extras sin respuesta. `WorkOrderPart` usa el trait `Auditable`.
  * El presupuesto complementario (otro presupuesto y otra OT en la misma recepción) sigue disponible.

### 6.8 Servicios, mecánicos y comisiones

* Catálogo `Service` (`SRV-…`, `standard_price`, `estimated_hours` opcional, `active|inactive`); usado no se elimina
  (`SERVICE_IN_USE`).
* Cada ítem `labor|service` nace asignado 100% al responsable. `PUT …/items/{item}/mechanics` reemplaza la asignación:
  sin porcentajes → partes iguales; con porcentajes → todos y suman 100 (`WORK_ORDER_ITEM_SHARES_INVALID`). Solo
  mecánicos `active`; solo `labor|service`; no con OT cerrada o ítem cancelado.
* `commission_percentage` y `hour_cost` se copian al asignar/cargar y se conservan. Comisión = total del ítem (sin IVA)
  × participación × %.
* Horas opcionales (`PATCH …/mechanics/{mechanic}/hours`, OT `in_progress|paused`). Sin horas el costo de mano de obra
  es `null` (desconocido), nunca cero.

### 6.9 Cierre económico

* Al completar la OT se crea `WorkOrderClosing` (inmutable, `WORK_ORDER_CLOSING_IMMUTABLE`) y las
  `WorkOrderCommission` congeladas.
* Venta = labor/service no cancelados + repuestos usados (neto × precio, incluye extras) + repuestos fuera de catálogo.
  Costo = repuestos + mano de obra conocida + comisiones. Si falta algún costo, el margen usa los conocidos e
  `is_cost_complete = false`. Importes sin IVA.
* `economic_summary` solo con `work-orders.view-economics`. Liquidación de comisiones por período
  (`MECHANIC_COMMISSIONS_NOTHING_TO_SETTLE`).

### 6.10 Facturación

* Una factura vigente (`draft|issued`) por OT completada (`WORK_ORDER_ALREADY_INVOICED`, `WORK_ORDER_NOT_INVOICEABLE`).
  Líneas = lo realmente usado; debe coincidir con la venta del cierre (`INVOICE_TOTAL_MISMATCH`).
* Precios con IVA incluido (sistema sin IVA × (100 + tasa) / 100), guaraníes enteros (`config/invoice.php`).
  IVA 10% = total ÷ 11; 5% = total ÷ 21. Descuento solo por línea.
* `draft → issued → cancelled`. Emitir exige RUC/CI y, a crédito, vencimiento ≥ hoy; toma el número del timbrado
  activo y vigente con `lockForUpdate` (`001-001-0000001`); números no se reutilizan. Emitida es inmutable
  (`INVOICE_NOT_EDITABLE`). Con pagos vigentes no se anula (`INVOICE_HAS_PAYMENTS`), ni con notas de crédito
  vigentes (`INVOICE_HAS_CREDIT_NOTES`, 6.28).
* La OT de un siniestro que requiere reparto no se factura por `POST work-orders/{id}/invoice`
  (`WORK_ORDER_REQUIRES_ALLOCATION_INVOICING`, 422; 6.27): se factura por responsable desde su reparto (6.29).
* Fuera de alcance hoy: SIFEN y la emisión de notas de crédito (las emitidas fuera se registran, 6.28). El PDF de la
  factura (6.18) es una representación sin valor fiscal.

### 6.11 Pagos

* Solo facturas `issued` (`INVOICE_NOT_PAYABLE`); varios pagos hasta el total (`PAYMENT_EXCEEDS_BALANCE`,
  `INVOICE_ALREADY_PAID`). Monto entero; fecha no futura ni anterior a la emisión.
* Medios `cash|card|transfer|check`; referencia obligatoria salvo efectivo; banco en cheque. Vuelto solo en efectivo
  (`amount_received` ≥ `amount`; se guarda `change_amount`).
* Recibo `REC-000001` solo para facturas a crédito. El pago no se edita: se anula con motivo.
* Saldo (total − pagos − notas de crédito vigentes, 6.28) y estado de cobro (`unpaid|partial|paid|credited`) se
  calculan; resumen de caja por medio de pago.
* Contrato: el listado de pagos trae `invoice_number` y `customer_name` de la factura (no en los pagos del detalle de
  la factura).

### 6.12 Entrega del vehículo

* Una entrega vigente por recepción (`DELIVERY_ALREADY_REGISTERED`, 409). Ninguna OT abierta, al menos una completada,
  cada completada con factura emitida y, si es al contado, sin saldo.
* Km final ≥ km de la recepción; conformidad obligatoria; firma opcional (imagen). Comprobante `ENT-000001`.
* La recepción pasa a `delivered`; `vehicles.mileage` solo avanza; la OT sigue `completed`. Anular con motivo devuelve
  la recepción a su estado anterior; si eso la reabre y el vehículo ya tiene otra recepción abierta, se rechaza
  (`DELIVERY_VEHICLE_HAS_OPEN_RECEPTION`, 409).
* Contrato: el listado de entregas trae `plate` y `client_name`.
* Con siniestro (6.29): deducible pendiente (`DELIVERY_CLAIM_DEDUCTIBLE_PENDING`) y OT con reparto controlada por
  porción y por factura (`DELIVERY_WORK_ORDER_NOT_FULLY_INVOICED`).

### 6.13 Historial y mantenimiento preventivo

* Historial (`vehicles/{vehicle}/history`, `/parts`, `/mileage`): solo lectura, sin tablas propias; costos solo con
  `work-orders.view-economics`. Lecturas de km de recepciones y entregas vigentes, con `decreased` si bajan.
* Planes (`maintenance_plans`) por km y/o días; con seguimiento no se eliminan. Seguimiento por vehículo
  (`vehicle_maintenances`) con intervalos propios opcionales.
* Registro automático al completar una OT con el servicio del plan (fecha de cierre, km de la recepción); manual sin
  fechas anteriores ni km menores al último.
* Km actual estimado por promedio km/día entre lecturas. Estado `unknown|ok|due_soon|overdue` (umbral en
  `config/maintenance.php`); alertas solo de planes activos con registro.

### 6.14 Reportes y dashboard

* Solo lectura, JSON. Período por defecto: mes en curso. Ventas y clientes por fecha de emisión (con IVA);
  rentabilidad y productividad por cierre de la OT (sin IVA).
* Permiso por reporte (`reports.sales|budgets|profitability|productivity|inventory|clients|receivables|claims`); el
  dashboard (`dashboard.view`) muestra solo los bloques permitidos. Cuentas por cobrar y siniestros: 6.30.

### 6.15 Compras

* `Purchase` (`COM-000001`) con `PurchaseItem`. Estados `draft → received → cancelled`; el borrador se edita
  (cabecera y líneas) y se elimina (soft delete); recibida es inmutable (`PURCHASE_NOT_EDITABLE`).
* Costos **con IVA incluido** como en la factura del proveedor; tasa por línea 0/5/10 (por defecto
  `invoice.default_tax_rate`). `net_unit_cost` = costo × 100 / (100 + tasa), 4 decimales: es lo que entra al
  inventario. IVA de la compra = total por tasa ÷ 11 / ÷ 21, en guaraníes. Condición `cash|credit` (`InvoiceCondition`);
  sin pagos a proveedores.
* Recibir (`POST purchases/{purchase}/receive`): con líneas (`PURCHASE_WITHOUT_ITEMS`), número y fecha de la factura del
  proveedor (`PURCHASE_DOCUMENT_REQUIRED`; fecha no futura), a crédito con vencimiento ≥ fecha de la factura
  (`PURCHASE_DUE_DATE_INVALID`), proveedor activo (`SUPPLIER_INACTIVE`) y número no repetido para el proveedor entre
  compras recibidas (`PURCHASE_DUPLICATE_DOCUMENT`, 409). Cada línea genera un movimiento `purchase`
  (`InventoryService::registerPurchase()`, referencia `purchase_item`) y crea o actualiza `part_supplier.last_cost`.
* Anular (`POST purchases/{purchase}/cancel`, motivo obligatorio): solo recibidas (`PURCHASE_NOT_CANCELLABLE`); una
  salida `purchase_cancellation` por línea al costo promedio; sin disponible suficiente → `INSUFFICIENT_STOCK`. El
  `last_cost` no se revierte.
* Permisos `purchases.index|show|store|update|destroy|receive|cancel` (las líneas usan `purchases.update`).
* Con pagos a proveedores vigentes no se anula (`PURCHASE_HAS_PAYMENTS`).

### 6.16 Pagos a proveedores

* `SupplierPayment` (`PAG-000001`) contra una compra `received`, contado o crédito (`PURCHASE_NOT_PAYABLE`): un pago,
  una compra; varios pagos hasta el total (`SUPPLIER_PAYMENT_EXCEEDS_BALANCE`, `PURCHASE_ALREADY_PAID`). Monto entero;
  fecha no futura ni anterior a la factura del proveedor (`SUPPLIER_PAYMENT_DATE_BEFORE_DOCUMENT`).
* Medios como en cobros (`PaymentMethod`): referencia obligatoria salvo efectivo, banco en cheque; sin vuelto.
  `supplier_receipt_number` opcional.
* El pago no se edita ni se elimina: se anula con motivo (`SUPPLIER_PAYMENT_NOT_CANCELLABLE`). Orden de bloqueo:
  compra → pago.
* Pagado, saldo y estado de pago (`unpaid|partial|paid`, solo recibidas) se calculan (`Purchase::balance()`).
* Cuentas por pagar (`GET accounts-payable`): a hoy, por proveedor; vence en `due_date` o, al contado, en la fecha de
  la factura; vencida si es anterior a hoy. Egresos por medio de pago en `supplier-payments/summary` (separado de
  `payments/summary`).
* Permisos `supplier-payments.index|show|store|cancel|summary` y `accounts-payable.view`. Fuera de alcance:
  retenciones, notas de crédito del proveedor, anticipos y moneda extranjera.

### 6.17 Notificaciones

* **Avisos al cliente** (`ClientNotification`, solo email: `NotificationChannel::MAIL`):
  * presupuesto enviado: uno en cada envío;
  * vehículo listo: cuando la recepción pasa a `completed`, uno por recepción;
  * factura `issued` a crédito con saldo: uno hasta 3 días antes del vencimiento y, vencida, uno cada 7 días;
  * mantenimiento: uno por vencer y otro vencido, por ciclo.

  Días y hora de la tarea diaria en `config/notifications.php`.
* **Registro y envío:** el aviso se registra dentro de la transacción del evento y se envía por cola con `afterCommit`.
  * Sin email o con `clients.notifications_enabled = false` queda `skipped` con el motivo.
  * El recordatorio de una factura cuyo RUC/CI (`customer_tax_id`) no es el documento del cliente queda `skipped`
    ("La factura está a nombre de otro destinatario."): no se avisa al dueño de una factura a nombre de otro. Se compara
    sin espacios, en mayúsculas y sin el dígito verificador (CI y RUC de la misma persona coinciden); sin documento del
    cliente, se avisa como siempre. El reenvío manual sigue permitido.
  * Un fallo de envío queda `failed` sin romper la operación que lo originó. No hay reintentos automáticos.
  * Los avisos automáticos no se repiten gracias a `dedupe_key` (único).
* **Reenvío** (`POST client-notifications/{id}/resend`): crea otro registro (`resent_from_id`, `created_by`) con el
  contenido actual del documento. Errores:
  * en cola → `CLIENT_NOTIFICATION_PENDING` (409);
  * documento o cliente eliminado → `CLIENT_NOTIFICATION_SUBJECT_UNAVAILABLE`;
  * baja → `CLIENT_NOTIFICATIONS_DISABLED`;
  * sin email → `CLIENT_WITHOUT_EMAIL` (estos tres, 422).
* **Avisos internos** (tabla `notifications`, `WorkshopNotification`, tipo `InternalNotificationType`), destinatarios por
  rol en `config/notifications.php`:
  * presupuesto aprobado/rechazado → recepcionista y jefe-taller;
  * OT completada → cajero y recepcionista;
  * repuesto sin stock al reservar → depósito y jefe-taller;
  * repuesto extra pendiente de aprobación del cliente → recepcionista y jefe-taller;
  * cuentas por pagar vencidas → cajero, una vez por día.

  El administrador no recibe avisos internos salvo que se agregue su rol a la configuración.
* **Tarea diaria:** `notifications:send-reminders` (facturas, mantenimientos y cuentas por pagar) se puede correr más de
  una vez por día sin repetir avisos.
* **Permisos:** `client-notifications.index|show|resend`. La bandeja interna `me/notifications` (filtros `unread`,
  `type`; `meta.unread_count`), `PATCH me/notifications/{id}/read` y `POST me/notifications/read-all` no usan permisos;
  un aviso ajeno responde 404.
* El email de "presupuesto enviado" adjunta el PDF del presupuesto (6.18).

### 6.18 Exportación y comprobantes imprimibles

* **PDF** (dompdf, `DocumentPdfService`, vistas en `resources/views/pdf`), con descarga `attachment`:
  * `GET budgets/{budget}/pdf`;
  * `GET invoices/{invoice}/pdf`;
  * `GET payments/{payment}/receipt`: solo pagos con recibo, si no `PAYMENT_WITHOUT_RECEIPT` (422);
  * `GET vehicle-deliveries/{delivery}/pdf`: con la firma embebida.

  Permisos: el `show` de cada documento. Borradores y anulados se imprimen con marca de agua (`BORRADOR`,
  `RECHAZADO`, `CANCELADO`, `ANULADA`/`ANULADO`).
* **Factura en PDF:** es una representación con la leyenda "no válido como documento fiscal". Con notas de crédito
  registradas, las lista con el saldo (6.28).
* **Datos del taller:** se leen de `config/company.php` (variables `COMPANY_*` del `.env`).
* **Reportes:** el mismo endpoint con `?format=csv|pdf` (mismo permiso `reports.*`). Sin `format` responde el JSON de
  siempre. `ReportExportService` no recalcula: convierte el array del reporte en secciones (resumen + tablas).
  * CSV para Excel en español: BOM, `;`, coma decimal.
  * Una clave nueva en un reporte necesita su etiqueta en `ReportExportService::LABELS`.
* **Formato de presentación:** `App\Support\Format` (`money`, `integer`, `quantity`). Es solo para mostrar: los
  cálculos siguen con BCMath.

### 6.19 Auditoría

* **Registro automático:** los modelos con el trait `App\Traits\Auditable` se registran solos en `audit_logs`
  (`AuditLogger`) en el alta, la modificación (solo los campos cambiados, antes y después), la baja y la restauración,
  con usuario, IP y user agent.
  * Modelos auditados: `Part`, `Service`, `Budget`, `BudgetItem`, `BudgetClientAcceptance`, `PartSupplier`, `Invoice`, `InvoiceItem`, `Payment`,
    `SupplierPayment`, `Purchase`, `PurchaseItem`, `User`, `Role`, `Permission`, `Client`, `Vehicle`, `Mechanic`,
    `InvoiceStamp`, `MaintenancePlan`, `DiagnosticItemTemplate`, `Insurer`, `InsurerContact`, `Claim`, `ClaimDocument` (sin su ruta interna),
    `InsurerAuthorization`, `InsurerAuthorizationItem`, `InternalException`, `WorkOrderAllocation`, `WorkOrderAllocationLine`, `InvoiceCreditNote` e `InvoiceCreditNoteItem`.
  * **Un modelo nuevo con precios, dinero, seguridad o datos maestros debe usar el trait.**
  * El stock ya se audita en `inventory_movements`.
* **Exclusiones:** `password`, `remember_token` y `token` se guardan como `********`. Los timestamps no se registran.
  Cada modelo puede excluir campos con `$auditExclude`. Un pivote sin clave propia se identifica con `auditContext()`.
* **Eventos sin modelo propio:** se registran con `AuditLogger::record()`.
  * Descarga de un documento de siniestro (`downloaded`, en `ClaimDocumentService::download`).
  * Login, login fallido y logout, en `AuthController`.
  * `permissions_synced` y `roles_synced`, porque `syncPermissions` y `syncRoles` de Spatie no disparan eventos.
    Cualquier sincronización nueva de roles o permisos debe registrarse igual.
* **Límite:** las actualizaciones masivas por query builder (`DB::table()->update()`, `Model::where()->update()`) no
  se auditan. Usar el modelo cuando el cambio deba quedar registrado.
* **Inmutabilidad:** `audit_logs` no se modifica ni se borra (`AUDIT_LOG_IMMUTABLE`, 409) y no se depura.
* **Consulta:** `GET audit-logs` (filtros `auditable_type`, `auditable_id`, `user_id`, `event`, `date_from`,
  `date_to`) y `GET audit-logs/{id}`. Permisos `audit-logs.index|show`, solo para el administrador.

### 6.20 Usuarios y roles

* Un rol por usuario (`role`, por nombre). La baja de usuario es soft delete y libera `name` y `email` (sufijo).
* Nadie puede eliminarse a sí mismo ni cambiarse el propio rol (`USER_SELF_ACTION`, 422); otro administrador sí.
* El rol `administrador` (`Role::ADMINISTRATOR`, del que depende el `Gate::before`) no se elimina ni se renombra ni
  cambia de guard (`ROLE_PROTECTED`, 422); su descripción y permisos sí se editan.
* Un rol con usuarios no se elimina (`ROLE_IN_USE`, 409): primero se les asigna otro.
* Los permisos los define `RolesAndPermissionsSeeder`; el ABM `permissions` existe, pero un permiso nuevo no habilita
  nada por sí solo (el frontend solo los asigna a roles).

### 6.21 Preferencias visuales y logo del sistema

* **Preferencias del usuario** (`user_preferences`, `UserPreferenceService`): ajustes de tema del frontend (Tabler 1.7).
  El catálogo de claves, valores y predeterminados está en `App\Support\UserPreferences`; agregar una opción es
  agregarla ahí (y en el frontend).
  * `GET|PATCH|DELETE me/preferences`, sin permiso: cada usuario, las suyas. `PATCH` parcial con al menos una clave;
    una clave o un valor fuera del catálogo da `422 VALIDATION_ERROR`. `DELETE` restaura los predeterminados.
  * Se guardan solo los valores distintos del predeterminado; la lectura (y `GET /me`) devuelve el juego completo.
  * No se auditan: no son datos de negocio ni de seguridad.
* **Logo del sistema** (`system_settings`, claves `logo_path` y `logo_black_path`, modelo `SystemSetting` auditado;
  `BrandingService`):
  * `GET branding` público (`logo_url`, `logo_black_url`); `POST|DELETE system-settings/logo` con
    `system-settings.update` (solo administrador). PNG o JPG hasta 2 MB, en el disco `public` (`branding/`); cada subida
    cambia la URL y borra los archivos anteriores.
  * Un PNG con transparencia se recorta a su contenido y genera la versión negra (`App\Support\LogoImage`); un JPG o
    un PNG opaco se guarda tal cual y `logo_black_url` es el mismo logo.
  * PDF: `DocumentPdfService::render()` pasa `logo` (data URI de la versión negra) a todas las vistas. Con logo, la
    cabecera no muestra el nombre del taller (el resto de sus datos y el RUC sí).
  * Emails: la cabecera HTML (`resources/views/vendor/mail/html/header.blade.php`) muestra `cid:logo@synctaller`;
    el mailable debe adjuntarlo con `BrandingService::embedLogo()` (hoy, `ClientNotificationMail`).
* **Favicon** (clave `favicon_path`, independiente del logo): `GET branding` lo informa en `favicon_url`;
  `POST|DELETE system-settings/favicon` con `system-settings.update`. PNG o ICO cuadrado (16 a 1024 px) o SVG, hasta
  512 KB, guardado tal cual. Un SVG con `<script>`, `<foreignObject>`, `<iframe>`, `<embed>`, `<object>`, eventos `on…` o
  `javascript:` se rechaza (`UploadSystemFaviconRequest`): el archivo queda público y se ejecutaría al abrir su URL.

### 6.22 Aseguradoras

Entrega M2 de `docs/especificacion-aseguradoras.html` (decisión del 2026-10-29; `InsurerService`).

* **Modelo:** `Insurer` (`ASE-000001`, serie de 3) vinculada a un **cliente empresa**, que es el destinatario de sus
  facturas. Razón social, RUC, email y teléfono se leen del cliente, no se duplican; se editan en el cliente. Propios:
  `status` (`InsurerStatus`: `active|inactive`), `payment_term_days`, `notes` y contactos. Baja lógica. `Auditable`.
* **Alta** (`POST insurers`): con `client_id` de un cliente empresa vigente **o** con `client` (sus datos, validados
  con las reglas de un cliente empresa), uno de los dos.
  * Cliente persona → `INSURER_CLIENT_NOT_COMPANY` (422).
  * Cliente con otra aseguradora vigente → `INSURER_CLIENT_ALREADY_LINKED` (409). Una dada de baja no retiene a su cliente:
    la unicidad se controla en el Service, no en la base.
* **Avisos por email (decisión D):** el cliente creado junto con la aseguradora nace con `notifications_enabled = false`.
  Uno existente conserva su configuración, y la respuesta lo advierte en `meta.warnings` (si tiene vehículos propios,
  seguiría recibiendo sus avisos).
* **Estado:** `POST insurers/{id}/activate|deactivate`; repetir → `INSURER_INVALID_STATUS_TRANSITION` (422). Inactiva: sin
  siniestros nuevos (desde M3).
* **Contactos** (`insurers/{insurer}/contacts`, anidado; `InsurerContactRole`: `claims|billing|adjuster_coordination|other`):
  un solo principal, marcar otro desmarca el anterior. `Auditable`.
* **Efecto sobre el cliente:** no se elimina (`CLIENT_LINKED_TO_INSURER`, 409) ni pasa a persona (`CLIENT_KIND_NOT_EDITABLE`).
* **Permisos:** `insurers.index|show` (recepcionista, jefe de taller y cajero) y `insurers.store|update|destroy|change-status`
  (recepcionista). Los contactos se leen con `insurers.show` y se editan con `insurers.update`.
* **Baja:** con siniestros (también cancelados) no se elimina (`INSURER_IN_USE`, 409): se desactiva. La modalidad del
  deducible llega con M10.

### 6.23 Siniestros

Entrega M3 de `docs/especificacion-aseguradoras.html` (decisiones del 2026-10-29; `ClaimService`).

* **Modelo:** `Claim` (`SIN-000001`) de una recepción y una aseguradora:
  * datos del siniestro: número y póliza (nulos), `claim_type` (`ClaimType`: `own|third_party`), fecha y descripción;
  * asegurado: `insured_*`, con `insured_client_id` si es cliente;
  * perito: `adjuster_*` y fechas de inspección;
  * `deductible_amount` (`decimal(14,2)`: nulo = desconocido, 0 = sin deducible) y `coverage_notes`;
  * `status` (`ClaimStatus`: `open|closed|cancelled`).

  `Auditable`.
* **Alta** (`POST claims`):
  * solo con la recepción abierta (`RECEPTION_CLOSED`) y la aseguradora activa (`INSURER_INACTIVE`, 422);
  * **un siniestro vigente (no cancelado) por recepción** (`RECEPTION_ALREADY_HAS_CLAIM`, 409): uno cancelado queda en el
    historial y se puede abrir otro;
  * el número no se repite en la misma aseguradora entre los no cancelados (`CLAIM_NUMBER_DUPLICATE`, 409);
  * en uno propio, sin datos del asegurado se copian los del cliente de la recepción; en uno de tercero el nombre del
    asegurado es obligatorio.
* **Edición** (`PATCH`): solo `open` (`CLAIM_NOT_EDITABLE`) y con la recepción editable. Recepción, aseguradora y tipo no
  cambian (`prohibited`: se cancela y se registra otro). El deducible no baja de lo asignado en los
  repartos confirmados (`CLAIM_DEDUCTIBLE_BELOW_ALLOCATED`, 422; 6.27) ni cambia con una factura vigente
  a la aseguradora (`CLAIM_DEDUCTIBLE_LOCKED`, 422; 6.29).
* **Cancelación** (`POST claims/{id}/cancel`, motivo obligatorio): solo `open` (`CLAIM_NOT_CANCELLABLE`) y sin lo cubierto en un
  reparto confirmado (`CLAIM_HAS_INSURER_ALLOCATIONS`, 422; 6.27). Las líneas `insurer` de los presupuestos en borrador
  de la recepción vuelven a `owner` (6.4).
* **Estado sincronizado con la recepción** (`ClaimStatusService`, en la transacción del evento): la entrega lo cierra, la
  anulación de la entrega lo reabre y la cancelación de la recepción lo cancela ("Recepción cancelada: <motivo>").
* **Visibilidad:** el detalle de la recepción trae `claim` (código, número, estado y aseguradora) solo si el usuario tiene
  `claims.show`. El mecánico ve la recepción, pero no pólizas ni datos del asegurado.
* **Permisos:** `claims.index|show` (recepcionista, jefe de taller y cajero) y `claims.store|update|cancel` (recepcionista y
  jefe de taller). También `GET receptions/{reception}/claim` (siniestro vigente, 404 si no tiene).

### 6.24 Documentos de siniestros

Entrega M4 de `docs/especificacion-aseguradoras.html` (decisión E y decisiones del 2026-10-29; `ClaimDocumentService`).

* **Almacenamiento:**
  * disco propio `claim_documents` (`storage/app/claim-documents`), privado, **sin `serve`** ni URL ni `temporaryUrl`;
  * nombre interno UUID con la extensión según el contenido (`claims/{claim}/{uuid}.ext`, armado solo en
    `ClaimDocumentService::pathFor`); el nombre original queda como dato;
  * se guardan `mime`, `size` y `sha256`. `path` nunca sale en la API ni en la auditoría.
* **Subida** (`POST claims/{claim}/documents`, multipart: `file`, `type` `ClaimDocumentType`, `notes`):
  * PDF, JPG, PNG o WEBP, hasta 10 MB (provisorio, V8). SVG, HTML y cualquier otro tipo se rechazan;
  * se validan la extensión, el tipo MIME **real** (por contenido) y que la extensión coincida con el contenido;
  * con el siniestro abierto o cerrado; cancelado → `CLAIM_NOT_EDITABLE`.

  En los tests, usar archivos reales (`new UploadedFile($path, $name, null, null, true)`): `UploadedFile::fake()` informa
  el tipo según la extensión del nombre y no prueba nada.
* **Descarga** (`GET claims/{claim}/documents/{document}/download`): solo por la API, resuelta por el registro
  (`scopeBindings`: un documento de otro siniestro → 404). Adjunto con nombre saneado y `X-Content-Type-Options: nosniff`.
  **Cada descarga queda en la auditoría** (`downloaded`).
* **Baja** (`DELETE …/{document}`, motivo obligatorio, solo administrador): lógica, con `deleted_by` y `deletion_reason`.
  El dado de baja responde 404 y ya no se lista; el archivo se conserva (retención legal pendiente, G10).
* **Permisos:** `claim-documents.index|download` (recepcionista, jefe de taller y cajero), `store` (recepcionista y jefe
  de taller) y `destroy` (solo administrador). Mecánico y depósito sin acceso.
* **Evidencia de una autorización** (M6): el documento vinculado (`insurer_authorization_id` en el Resource) no se da de
  baja (`CLAIM_DOCUMENT_IN_USE`, 409). Sin antivirus (deuda #16).

### 6.25 Autorizaciones de la aseguradora

Entrega M6 de `docs/especificacion-aseguradoras.html` (decisiones del 2026-10-29; `InsurerAuthorizationService`).

* **Modelo:** `InsurerAuthorization` por presupuesto y versión (`version` 1, 2, …), con el siniestro abierto de la
  recepción, `result` (`InsurerAuthorizationResult`: `full|partial|rejected`, calculado), `status`
  (`InsurerAuthorizationStatus`: `current|superseded|invalidated`), número, fecha (`decided_at`, no futura), medio
  (`ClientDecisionChannel`, obligatorio), perito, notas, quién la registró y `authorized_total`. Una línea
  (`InsurerAuthorizationItem`) por cada ítem a cargo de la aseguradora, con `decision`
  (`InsurerAuthorizationDecision`: `authorized|partial|not_covered`), cantidad, precio unitario e importe autorizados y
  una **copia del ítem** (descripción, cantidad y total presupuestados): el ítem puede editarse o borrarse al reabrir
  (la clave foránea queda en `null`). `Auditable`.
* **Registro** (`POST budgets/{budget}/insurer-authorizations`):
  * presupuesto `sent|approved` (`INSURER_AUTHORIZATION_BUDGET_NOT_AUTHORIZABLE`), siniestro abierto en la recepción
    (`BUDGET_WITHOUT_CLAIM`) y líneas `insurer` (`INSURER_AUTHORIZATION_WITHOUT_INSURER_ITEMS`), todos 422;
  * **todas** las líneas `insurer` y solo ellas: falta alguna → `INSURER_AUTHORIZATION_ITEMS_INCOMPLETE`; una del dueño
    o de otro presupuesto → `INSURER_AUTHORIZATION_ITEM_MISMATCH` (422);
  * `authorized` toma cantidad e importe del ítem; `not_covered`, cero; `partial` exige `authorized_total` (> 0) y
    acepta `authorized_quantity` (por defecto, la del ítem). **Tope:** ni más importe ni más cantidad que lo
    presupuestado (`INSURER_AUTHORIZATION_EXCEEDS_BUDGET`, 422, P7): el exceso va en un presupuesto complementario;
  * **importes sin IVA**, como el presupuesto: `amounts_include_tax` es **obligatorio** y, si es `true`, el importe se
    divide por (100 + `invoice.default_tax_rate`) / 100 con BCMath (V2 pendiente: la unidad la elige quien carga).
* **Versiones:** registrar otra sustituye a la vigente (`superseded`, con `superseded_by_id`). Si alguna línea autoriza
  más que en la vigente, `document_ids` es obligatorio (`INSURER_AUTHORIZATION_EVIDENCE_REQUIRED`, 422): documentos del
  mismo siniestro, vigentes y sin vincular (`INSURER_AUTHORIZATION_DOCUMENT_MISMATCH`, 422). Los documentos quedan
  vinculados y ya no se dan de baja (6.24).
* **Invalidación:** reabrir o cancelar el presupuesto y cancelar la recepción (sus presupuestos) invalidan la vigente
  (`invalidated`, con fecha y motivo). Cancelar el siniestro no la toca (como las líneas de un presupuesto enviado,
  6.4). Rechazar el presupuesto tampoco: lo hace la reapertura.
* **Inmutable:** el contenido no cambia y no se borra (`INSURER_AUTHORIZATION_IMMUTABLE`, 409); solo pasa de `current`
  a `superseded` o `invalidated`.
* **Consulta:** `GET budgets/{budget}/insurer-authorizations` (todas las versiones, en orden) y `GET …/{id}` (scoped).
  El detalle del presupuesto trae `insurer_authorization` (la vigente, resumida, o `null`) solo con
  `insurer-authorizations.show`.
* **Permisos:** `insurer-authorizations.index|show` (recepcionista, jefe de taller y cajero) y `store` (recepcionista y
  jefe de taller).
* **Uso:** la OT de un presupuesto con líneas `insurer` exige la autorización vigente (6.5, M7).

### 6.26 Excepciones internas y aceptación del dueño en siniestros

Entrega M7 de `docs/especificacion-aseguradoras.html` (decisiones B y C y decisiones del 2026-10-29; `OwnerShareService`,
`InternalExceptionService`).

* **Parte del dueño** (sin IVA, `OwnerShareService`): sus líneas más lo no autorizado de las líneas `insurer`
  (total − autorizado de la versión vigente del siniestro abierto). Sin siniestro abierto, todo es del dueño; **sin
  autorización todavía, las líneas `insurer` quedan pendientes y no se le suman**. Sin líneas `insurer`, es el subtotal
  (particular, como siempre).
* **Aceptación** (`budget_client_acceptances`, 6.4): `approve`, `reject` y la re-aceptación guardan esa parte en
  `owner_amount`, y con siniestro abierto también `insurer_authorization_id` (la vigente) y `deductible_amount` (el del
  siniestro). El Resource muestra esas dos claves solo con `claims.show`. El importe aceptado es el de la última
  aprobación o re-aceptación.
* **Re-aceptación** (`POST budgets/{budget}/client-acceptances`, permiso `budgets.approve`; `channel` obligatorio,
  `contact` y `notes` opcionales): presupuesto `approved` y su OT no cancelada (otro estado, o la OT `cancelled` →
  `BUDGET_NOT_REACCEPTABLE`, 422; recepción cerrada → `RECEPTION_CLOSED`). Con la OT completada también (corrección
  del 2026-10-29 en M8: es la salida del Ejemplo 2 cuando el reparto supera lo aceptado). Kind `re_acceptance`; el importe lo calcula
  el sistema: sin OT, por el presupuesto; con la OT, por la ejecución actual sin los extras (lo resuelto por lo usado,
  lo pendiente por lo previsto). No exige que haya subido y no cambia el estado. `GET` lista todas las respuestas
  (`budgets.show`).
* **Excepciones** (`internal_exceptions`, `POST|GET internal-exceptions`, `GET internal-exceptions/{id}`; body `type`,
  `subject_id`, `amount`, `reason` de 10 a 1000):
  * `start_without_authorization`: sobre un presupuesto `sent|approved`, sin OT, con líneas `insurer`, siniestro abierto
    y **sin** autorización vigente; sin importe (`prohibited`). La consume el alta de la OT;
  * `workshop_absorption`: sobre una OT no cancelada de un siniestro abierto con líneas `insurer`, con importe
    obligatorio (> 0). Se usa en el reparto (M8);
  * sujeto inválido → `INTERNAL_EXCEPTION_SUBJECT_INVALID` (422); otra del mismo tipo sin usar para el mismo sujeto →
    `INTERNAL_EXCEPTION_ALREADY_GRANTED` (409);
  * inmutables (`INTERNAL_EXCEPTION_IMMUTABLE`, 409): solo se registra una vez la operación que la consumió
    (`consumed_at`, `consumed_by`). `Auditable`. **Ninguna sube lo que paga la aseguradora ni lo aceptado por el dueño.**
  * Listado con filtros `type`, `subject_type` (`budget|work_order`), `subject_id` y `consumed` (booleano).
* **Advertencia de B** (`ShowWorkOrderResource.owner_amount_warning`, solo con `claims.show`): con líneas `insurer`, si
  la parte potencial del dueño (ejecución actual + extras aprobados) supera lo aceptado (última aceptación + extras
  aprobados, cantidad × precio) o el deducible del siniestro subió desde la aceptación. Trae `accepted_amount`,
  `potential_amount`, `difference`, `amount_exceeded`, `accepted_deductible`, `current_deductible`,
  `deductible_increased` y `message`; `null` si no hay nada que advertir. Informativa: no bloquea (el control es al
  confirmar el reparto, M8).
* **Advertencia de P5:** el detalle de la OT trae `has_claim` (siniestro abierto en la recepción, sin permiso) y el alta
  de un extra con siniestro abierto responde `meta.warnings` ("Este repuesto quedará a cargo del dueño…"). No cambia
  las reglas del extra (6.7).
* **Permisos:** `internal-exceptions.grant` (jefe de taller) e `internal-exceptions.index|show` (jefe de taller y
  cajero). La recepcionista no otorga ni consulta excepciones.

### 6.27 Reparto económico

Entrega M8 de `docs/especificacion-aseguradoras.html` (§4.5, §5.7, §6.2–6.3; decisiones del 2026-10-29;
`AllocationService`, `WorkOrderSaleBreakdown`).

* **Cuándo:** una OT **requiere reparto** si su presupuesto tiene líneas `insurer` y la recepción un siniestro no
  cancelado (`OwnerShareService::requiresAllocation`; `requires_allocation` en el detalle de la OT). Se reparte con la OT
  `completed` (`WORK_ORDER_NOT_ALLOCATABLE`, 422) y el siniestro abierto (`CLAIM_NOT_EDITABLE`). Una OT particular no
  tiene reparto (`ALLOCATION_NOT_REQUIRED`, 422) y se factura como siempre (R10).
* **Lo vendido por concepto** (`WorkOrderSaleBreakdown`): mano de obra, servicios y repuestos fuera de catálogo por lo
  presupuestado (salvo cancelados), repuestos de catálogo y extras por lo usado. Lo comparten la factura (sus líneas,
  sin cambios: hay un test de igualdad) y el reparto; suma la venta del cierre.
* **Modelo:** `WorkOrderAllocation` (versión por OT; `draft|confirmed|superseded`; `sale_total` de control;
  `deductible_amount`, la parte del deducible asignada a la OT tal como se informa) y sus porciones
  `WorkOrderAllocationLine` (`AllocationPortionType`: `insurer_covered|owner_responsibility|owner_uncovered|workshop_absorbed`;
  concepto, ítem del presupuesto, responsable, línea de autorización, excepción e importe sin IVA > 0; una por concepto y
  tipo). Los dos `Auditable`. Un borrador por OT (`ALLOCATION_DRAFT_EXISTS`, 409); confirmada es inmutable
  (`ALLOCATION_IMMUTABLE`, 409) salvo su sustitución.
* **Generación** (`POST work-orders/{id}/allocations`, a mano): si hay una confirmada, copia sus porciones (corrección);
  si no, propone: por ítem `insurer`, la aseguradora (su cliente empresa) recibe hasta lo autorizado en la versión
  vigente, sumando los conceptos del ítem en orden, y el resto va al dueño como `owner_uncovered`; las líneas del dueño y
  los extras, `owner_responsibility`. Sin autorización, todo al dueño (con advertencia). El deducible sugerido es el
  restante del siniestro (los borradores no lo reservan; nulo si es desconocido).
* **Edición** (`PATCH`): `lines` reemplaza todas las porciones (`source_type`, `source_id`, `portion_type`, `amount`,
  `internal_exception_id` en la absorción); responsable, ítem y línea de autorización se derivan. Concepto ajeno o tipo
  repetido → `ALLOCATION_LINE_MISMATCH` (422). También `deductible_amount` (no vacío) y `notes`. Solo en borrador
  (`ALLOCATION_NOT_EDITABLE`, 422; también para `DELETE`).
* **Vista previa:** el borrador trae `checks` (cada regla con `ok`, mensaje y `error_code`) y todas las versiones
  `summary` (por responsable: `insurer|owner|workshop`; total; deducible y `deductible_fiscal_pending`, calculado =
  deducible > 0; advertencias de autorización faltante o cambiada).
* **Confirmación** (`POST …/{allocation}/confirm`; bloqueo siniestro → OT → reparto → excepción), en este orden:
  deducible desconocido (`CLAIM_DEDUCTIBLE_UNKNOWN`); deducible mayor que el aceptado por el dueño
  (`CLAIM_DEDUCTIBLE_NOT_ACCEPTED`); R1 `ALLOCATION_INCOMPLETE`; R2 `ALLOCATION_TOTAL_MISMATCH` (409); R4
  `ALLOCATION_EXTRA_NOT_COVERABLE`; R3 `ALLOCATION_EXCEEDS_AUTHORIZATION` (contra la autorización vigente); R6
  `ALLOCATION_ABSORPTION_NOT_AUTHORIZED` (excepción `workshop_absorption` de esta OT, sin usar, con importe ≥ lo
  absorbido; se consume al confirmar); R5 `ALLOCATION_OWNER_AMOUNT_NOT_ACCEPTED` (lo del dueño ≤ última aceptación +
  extras aprobados, sin tolerancia; se resuelve con re-aceptación, absorción o complementario); R7
  `ALLOCATION_DEDUCTIBLE_EXCEEDS_CLAIM` (deducibles confirmados del siniestro ≤ el suyo). Todos 422 salvo R2. La versión
  confirmada anterior pasa a `superseded`. Con deducible mayor a 0 se confirma igual: no se factura hasta M10 (A).
* **Efectos:** `POST work-orders/{id}/invoice` rechaza la OT que requiere reparto
  (`WORK_ORDER_REQUIRES_ALLOCATION_INVOICING`, 422; decisión del 2026-10-29: desde M8, para no facturarla entera al
  dueño antes de M9). El siniestro no se cancela con lo cubierto en un reparto confirmado
  (`CLAIM_HAS_INSURER_ALLOCATIONS`, 422) ni su deducible baja de lo asignado (`CLAIM_DEDUCTIBLE_BELOW_ALLOCATED`, 422).
  El detalle de la OT trae `allocation` (resumen de la confirmada) con `work-order-allocations.show`.
* **Permisos:** `work-order-allocations.index|show` (recepcionista, jefe de taller y cajero) y
  `store|update|confirm|destroy` (cajero).
* **Facturación:** por responsable desde el reparto confirmado, y la corrección de un reparto facturado
  (`ALLOCATION_HAS_INVOICES`) en 6.29.

### 6.28 Notas de crédito emitidas fuera del sistema

Decisión P10 (handoff 2.66 y 2.69; `CreditNoteService`). SyncTaller no emite notas de crédito: registra la emitida en
talonario u otro sistema sobre la factura que corrige.

* **Modelo:** `InvoiceCreditNote` (`number` y `stamp_number` de la nota externa, `issue_date`, `reason`, `status`
  `CreditNoteStatus`: `registered|cancelled`, totales e IVA por tasa como la factura) con una línea
  `InvoiceCreditNoteItem` por línea de factura acreditada: `amount` con IVA (guaraníes enteros) y `base_amount` sin IVA,
  proporcional a la línea (respeta el descuento) o exacta si se acredita todo lo que le queda. Los dos `Auditable`.
* **Registro** (`POST invoices/{invoice}/credit-notes`, permiso `invoice-credit-notes.store`, cajero):
  * solo factura `issued`, también particular (`INVOICE_NOT_CREDITABLE`, 422);
  * por línea y parcial: ninguna línea más de lo que le queda (`CREDIT_NOTE_EXCEEDS_LINE`); línea ajena
    (`CREDIT_NOTE_ITEM_MISMATCH`), todos 422;
  * **hasta el saldo** (`CREDIT_NOTE_EXCEEDS_BALANCE`, 422): para acreditar lo cobrado, primero se anula el pago. Sin
    saldos a favor ni devoluciones;
  * fecha no futura ni anterior a la emisión (`CREDIT_NOTE_DATE_BEFORE_INVOICE`, 422); el plazo de un año no se valida
    (V18 pendiente);
  * número (letras, números, guiones, barras y puntos, ≤ 30) único entre las vigentes (`CREDIT_NOTE_DUPLICATE_NUMBER`, 409).
* **Inmutable** (`CREDIT_NOTE_IMMUTABLE`, 409). Un registro mal cargado se anula con motivo
  (`POST …/{creditNote}/cancel`, `invoice-credit-notes.cancel`; repetir → `CREDIT_NOTE_NOT_CANCELLABLE`, 422).
* **Efectos:** el saldo de la factura descuenta lo acreditado (pagos, entrega al contado, recordatorios, cuentas por
  cobrar e historial lo toman solos; donde se precarga `paid_total` se precarga `credited_total`). Estado de cobro
  `credited` ("Acreditada") si la factura queda saldada solo con notas, sin pagos; con pagos sigue `paid`. Con notas
  vigentes la factura no se anula (`INVOICE_HAS_CREDIT_NOTES`, 422); la anulación de siempre sigue igual (V19). El
  detalle de la factura agrega `payment_summary.credited_total` y `credit_notes[]`; el PDF, la lista de notas y el
  saldo; el reporte de ventas, el bloque `credit_notes` (por fecha de la nota) y `net_total` (`total` sigue siendo lo
  emitido). No hay aviso al cliente por una nota de crédito.
* **Consulta:** `GET invoices/{invoice}/credit-notes` y `GET …/{creditNote}` (scoped) con `invoices.show`.
* **M9:** el facturado neto de una porción del reparto es la base de sus líneas de factura menos la acreditada.

### 6.29 Facturas por responsable

Entrega M9 de `docs/especificacion-aseguradoras.html` (§4.6, §7.1; decisiones A y F y decisiones del 2026-10-29;
`InvoiceService::generateForAllocation`, `AllocationBillingService`).

* **Datos:** `invoices.allocation_id` (versión del reparto que factura) e `invoice_items.allocation_line_id` (porción),
  nulos en el flujo particular. El **facturado neto** de una porción es la base de sus líneas en facturas vigentes menos
  la base acreditada por notas de crédito (6.28).
* **Generar** (`POST work-orders/{id}/allocations/{allocation}/invoices`, body `client_id`, permiso `invoices.store`):
  * reparto confirmado (`ALLOCATION_NOT_CONFIRMED`, 422); deducible del siniestro informado e igual a 0 (A: mayor a 0
    → `ALLOCATION_DEDUCTIBLE_MODE_UNDEFINED`, nulo → `CLAIM_DEDUCTIBLE_UNKNOWN`, 422);
  * el cliente con porciones en ese reparto (`ALLOCATION_CLIENT_NOT_RESPONSIBLE`, 422) y algo pendiente
    (`ALLOCATION_NOTHING_TO_INVOICE`, 422); **una vigente por cliente y versión** (`WORK_ORDER_ALREADY_INVOICED`, 409):
    lo acreditado se vuelve a facturar desde una versión nueva del reparto;
  * una línea por porción con algo pendiente: el concepto entero con su cantidad y precio; una parte, con cantidad 1,
    base igual al importe y la cantidad real en la descripción (Ejemplo 5);
  * cabecera con los datos del cliente; la de la aseguradora **a crédito** con vencimiento hoy + `payment_term_days`
    (30 si no tiene), la del dueño **al contado** (editables en borrador como siempre). El taller y el deducible no se
    facturan.
* **Emitir:** con reparto, revalida que siga confirmado (`ALLOCATION_SUPERSEDED`, 409), el deducible (A) y R8 (lo
  facturado por porción ≤ la porción, `INVOICE_EXCEEDS_ALLOCATION`, 409). El PDF de la factura a la aseguradora trae el
  número de siniestro y la póliza.
* **Corregir el reparto** (P6 con P10): la versión confirmada anterior no puede tener facturas vigentes con facturado
  neto (los borradores cuentan): `ALLOCATION_HAS_INVOICES` (409) con `errors.invoices[]` (`invoice_id`, `number`,
  `customer_name`, `status`, `has_payments`, `credited_total`).
* **Entrega** (`VehicleDeliveryService`): con un siniestro abierto de deducible mayor a 0 o nulo, la recepción no se
  entrega (`DELIVERY_CLAIM_DEDUCTIBLE_PENDING`, 422; toda la recepción, también las OT sin reparto). Una OT con reparto
  necesita el confirmado con todas sus porciones (salvo las del taller) facturadas y emitidas
  (`DELIVERY_WORK_ORDER_NOT_FULLY_INVOICED`, 422); contado y crédito por factura (`DELIVERY_INVOICE_NOT_PAID` para la
  del dueño al contado; la de la aseguradora a crédito puede estar impaga). Las OT particulares, como siempre.
* **Siniestro:** con una factura vigente a la aseguradora el deducible no cambia (`CLAIM_DEDUCTIBLE_LOCKED`, 422).
* **Contrato (F):** el detalle de la OT agrega `invoices[]` (vigentes, con destinatario, condición, saldo y estado de
  cobro) e `invoices_count`, solo con `invoices.show`. **`invoice` es una clave heredada:** sigue siendo la última
  factura vigente, y el frontend nuevo usa `invoices[]`. El historial del vehículo agrega `invoices[]` en cada OT y
  `summary.by_recipient` (`owner`/`others`). Las facturas agregan `allocation_id` y `items[].allocation_line_id`; el
  listado filtra por `work_order_id`.

### 6.30 Reportes del módulo de aseguradoras

Entrega M11 de `docs/especificacion-aseguradoras.html` (decisiones del 2026-10-30; `ReportService::receivables`,
`ClaimSummaryService`). Solo lectura, sin tablas nuevas. **Los avisos a la aseguradora quedaron fuera** (decisión del
usuario: esperan V7; mientras tanto rige D).

* **Tipo de destinatario** (`InvoiceRecipientType`: `insurer|customer`): `insurer` si el cliente de la factura es el de
  una aseguradora, también dada de baja; si no, `customer`. Las filas traen `recipient_type` y `recipient_label`.
* **Cuentas por cobrar** (`GET reports/receivables`, permiso `reports.receivables`, cajero): a hoy (`as_of`), sin
  período. Facturas `issued` con saldo (`Invoice::balance()`: descuenta pagos y notas de crédito), con IVA. Vence en
  `due_date` (crédito) o en la emisión (contado), como las cuentas por pagar; vencida si es anterior a hoy.
  * `totals` y `by_recipient`: facturas, saldo, `overdue`, `not_overdue` y antigüedad por días de atraso
    (`days_1_30`, `days_31_60`, `days_61_90`, `days_over_90`);
  * `clients`: una fila por cliente con su tipo, saldo, vencido, vencimiento más antiguo y máximo de días;
  * filtros `recipient_type` y `client_id` (con el cliente, también `invoices`: número, OT, siniestro, días, cobrado,
    acreditado y saldo).

  El bloque `receivables.clients` del reporte de clientes agrega `recipient_type` y `recipient_label` (aditivo).
* **Resumen del siniestro** (`GET claims/{claim}/summary`, permiso `claims.show`): sobre todos los presupuestos y OT de
  su recepción (también complementarios). Bloques según permiso (decisión del usuario):
  * `budgets` (con `claims.show`, sin IVA): presupuestos no cancelados con lo de la aseguradora y del dueño, la
    autorización vigente de este siniestro y lo aceptado por el dueño. **Los totales suman solo los aprobados**;
  * `allocations` (con `work-order-allocations.show`, sin IVA): por OT no cancelada, el reparto confirmado de este
    siniestro por responsable (aseguradora, dueño, taller) y el deducible asignado (`deductible_fiscal_pending`);
  * `billing` (con `invoices.show`, con IVA): facturas vigentes de las OT de la recepción por destinatario (la
    aseguradora del siniestro o los demás). Los totales (`net_invoiced` = emitido − acreditado, `collected`, `balance`)
    suman solo las emitidas; los borradores se listan y se cuentan aparte;
  * `alerts`: deducible desconocido o mayor a 0 (siniestro abierto, A), OT completada que requiere reparto sin uno
    confirmado (si ve el reparto) y reparto con porciones sin facturar o emitir (si ve la facturación).

  Los bloques sin IVA y el de IVA no se mezclan.
* **Reporte de siniestros** (`GET reports/claims`, permiso `reports.claims`, cajero): una fila por siniestro con la fecha
  del siniestro en el período (por defecto, el mes en curso), filtros `insurer_id` y `status`; presupuestado a la
  aseguradora, autorizado, aceptado, repartido, facturado neto, cobrado y saldo por destinatario, y cantidad de alertas.
  Muestra todos los bloques: el permiso del reporte alcanza.
* Los dos reportes se exportan con `?format=csv|pdf` (etiquetas en `ReportExportService::LABELS`).

---

## 7. Deuda técnica conocida

`docs/handoff.md`, sección 14. Las deudas nuevas se registran ahí y no se corrigen sin decisión previa del usuario.
Abiertas (reverificado el 2026-10-29):

* **#13, clientes aseguradoras:** en implementación (`docs/especificacion-aseguradoras.html`, versión 3): M1–M9 y P10
  hechas (6.1, 6.4, 6.5 y 6.22–6.29) y M11 sin los avisos (6.30); faltan M10 (deducible mayor a 0, espera G1), M12
  (retenciones, espera V17) y los avisos a la aseguradora (esperan V7).
* **#14:** disco `local` con `serve` activo y sin uso. No se usa para documentos nuevos: los de siniestros van al disco
  `claim_documents`, sin `serve` (6.24).
* **#15:** fotos y firmas con URL pública (deuda de seguridad, P8). Se resuelve después del storage por taller y antes
  del primer taller comercial; mientras tanto, nada nuevo en el disco `public` salvo branding.
* **#16:** sin antivirus para los archivos subidos (riesgo técnico).
* **Discrepancias D1–D10** (14.1): D3, D7 y D8 esperan decisión (D9 se resolvió con M1; D4 y D6, solo documentación, el 2026-10-29).

La #11 (repuestos extra sin aprobación del cliente) se resolvió el 2026-10-23; ver 6.7.

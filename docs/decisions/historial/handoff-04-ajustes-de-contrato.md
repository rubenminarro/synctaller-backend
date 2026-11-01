<!-- Handoff original: ajustes de contrato y datos (secciones 2.39 a 2.53). Líneas 1117–1430 de docs/handoff.md hasta el commit 9cae759. Mapa de secciones: docs/handoff.md. -->

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


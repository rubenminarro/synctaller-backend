# SyncTaller — Sistema de gestión para taller mecánico

API REST para gestionar el flujo completo de atención de un vehículo en un taller mecánico: desde la recepción
hasta el pago de la factura.

```text
Cliente → Vehículo → Recepción → Diagnóstico → Presupuesto → Aprobación → Orden de trabajo
       → Ejecución (servicios, mano de obra, repuestos) → Cierre económico → Factura → Pago → Entrega
```

> **Documentación del proyecto**
>
> | Documento | Contenido |
> |---|---|
> | `README.md` (este) | Qué es, cómo se instala y ejecuta, estado general |
> | [`CLAUDE.md`](CLAUDE.md) | Reglas operativas, convenciones y reglas de negocio vigentes (para quien modifica el código) |
> | [`docs/handoff.md`](docs/handoff.md) | Historia y decisiones de diseño por fase, roadmap original y deuda técnica conocida |
> | [`docs/testing/guia-postman.md`](docs/testing/guia-postman.md) | Pruebas manuales de la API, por fase |

---

## Stack

Versiones verificadas en `composer.lock` y en el entorno local (actualizadas el 2026-10-29):

| Capa | Tecnología |
|---|---|
| Lenguaje | PHP `^8.3` (desarrollo con PHP 8.3) |
| Backend | Laravel 13 (`laravel/framework` 13.38) |
| Autenticación | Laravel Sanctum 4.3 (tokens Bearer) |
| Roles y permisos | spatie/laravel-permission 8.3 (guard `api`) |
| Tests | PHPUnit 12.5 |
| PDF | barryvdh/laravel-dompdf 3.1 (dompdf 3.1; requiere la extensión `gd` para imágenes) |
| Base de datos | MySQL (Laragon con MySQL 8.4); SQLite en archivo (`database/testing.sqlite`) para los tests |
| Assets | Vite 7 + Tailwind CSS 4 + axios (scaffold por defecto de Laravel, sin uso: la app es solo API) |
| Frontend | **No incluido en este repositorio** (ver [Frontend](#frontend)) |
| Entorno local | Laragon (Windows) con **Nginx** en el puerto 80 (no Apache: ver `docs/handoff.md`, sección 14, #12) |

---

## Módulos

| Módulo | Qué cubre |
|---|---|
| Autenticación | Login / logout con token Sanctum |
| Usuarios, roles y permisos | Roles `administrador`, `mechanic`, `recepcionista`, `jefe-taller`, `cajero` y `deposito`; permisos por operación |
| Clientes y vehículos | Clientes (personas o empresas), marcas, modelos, vehículos (también anidados por cliente) |
| Recepción | Ingreso del vehículo, categorías de servicio, checklist y fotos; estado derivado del flujo (en proceso, en reparación, terminado) y cancelación con motivo |
| Diagnóstico | Diagnóstico técnico con ítems y fotos; cada ítem se elige de un catálogo reutilizable (copia sus valores, que se ajustan en cada revisión) |
| Presupuestos | Ítems de mano de obra, servicio y repuesto, cada uno a cargo del dueño o de la aseguradora; estados `draft → sent → approved`; respuesta del cliente registrada (aprobación, rechazo y re-aceptación) |
| Órdenes de trabajo | Nace de un presupuesto aprobado (en un siniestro, con la autorización de la aseguradora y la aceptación del dueño, o con una excepción interna); estados, ítems, mecánico responsable |
| Repuestos e inventario | Catálogo, proveedores, stock con movimientos inmutables, reservas y consumo en la OT; repuestos extra con la aprobación del cliente registrada |
| Compras | Factura del proveedor con IVA incluido (10% / 5% / exento por línea), borrador → recibida (ingresa el stock al costo sin IVA y actualiza el costo del proveedor) → anulada (revierte el stock) |
| Pagos a proveedores | Pagos parciales contra compras recibidas (contado o crédito), comprobante `PAG-000001`, anulación con motivo, resumen de egresos y cuentas por pagar por proveedor (vencido / por vencer) |
| Servicios y mano de obra | Catálogo de servicios, reparto entre mecánicos, horas reales, comisiones |
| Cierre económico | Al completar la OT se congelan venta, costos, margen y comisiones; liquidación de comisiones |
| Facturación | Timbrado, numeración `001-001-0000001`, IVA 10% / 5% / exento, contado o crédito |
| Pagos | Pagos parciales, medios de pago, vuelto en efectivo, recibos para crédito, saldo y resumen de caja |
| Entrega del vehículo | Comprobante `ENT-000001`, kilometraje final, quién retira, conformidad y firma; exige factura emitida y pagada si es al contado |
| Historial del vehículo | Resumen, visitas (diagnóstico, OT, repuestos, factura, entrega), repuestos usados y lecturas de kilometraje |
| Mantenimiento preventivo | Planes por km y/o días, registro automático desde la OT, km actual estimado por uso y alertas de vencimiento |
| Reportes y dashboard | Dashboard por permisos; reportes de ventas, presupuestos, rentabilidad, productividad, inventario y clientes |
| Auditoría | Quién cambió qué y cuándo (antes/después, IP) en precios, costos, facturación, pagos, compras, usuarios, roles, permisos y datos maestros; login, login fallido y logout. Inmutable, solo para el administrador |
| Exportación y comprobantes | Reportes en CSV (Excel) y PDF; PDF de presupuesto, factura (representación sin valor fiscal), recibo y comprobante de entrega |
| Notificaciones | Emails al cliente (presupuesto enviado, vehículo listo, factura por vencer / vencida, mantenimiento próximo / vencido) con registro, reenvío y baja por cliente; bandeja interna por usuario (presupuesto aprobado/rechazado, OT completada, repuesto sin stock, cuentas por pagar vencidas) |
| Aseguradoras y siniestros (en curso) | Aseguradoras vinculadas a un cliente empresa, con contactos; siniestros por recepción (asegurado, perito, deducible) con documentos privados descargables solo por la API; autorizaciones de la aseguradora por línea y versionadas; excepciones internas. Faltan el reparto y la facturación por responsable (`CLAUDE.md` 6.22–6.26) |
| Preferencias y logo | Preferencias visuales de cada usuario para el frontend (tema, colores, tipografía, distribución y navegación); logo del sistema (solo administrador; con un PNG transparente se recorta y se genera una versión negra) en los PDF y los emails a clientes |

### Estado real (verificado el 2026-10-29)

| Estado | Qué |
|---|---|
| Implementado y probado | Todos los módulos de la tabla anterior: el roadmap original (FASES 10–20), compras (FASE 21), pagos a proveedores (FASE 22), notificaciones (FASE 23), exportación y comprobantes imprimibles (FASE 24), auditoría (FASE 25), preferencias y logo del sistema, catálogo de ítems de diagnóstico (2026-10-24) y los módulos base. 268 rutas API y 440 tests automatizados en verde; cada módulo tiene sus tests en `tests/Feature` |
| En curso | Aseguradoras y siniestros: M1–M7 de `docs/especificacion-aseguradoras.html` implementadas; siguen el reparto económico (M8) y la facturación por responsable (M9–M12) |
| Pendiente, sin diseño | Notificaciones por WhatsApp |

---

## Requisitos

- PHP 8.3 o superior con las extensiones `pdo_mysql`, `bcmath`, `mbstring`, `gd` (imágenes en los PDF) y `zip` (y
  `pdo_sqlite` para los tests). Sin `zip` en el PHP de consola, Composer no puede descomprimir los paquetes y los
  clona uno por uno con git: la instalación tarda muchísimo. En Laragon, descomentar `extension=zip` en el `php.ini`.
- Composer
- MySQL 8 (Laragon trae MySQL; en desarrollo se usa 8.4)
- Node.js y npm: **opcionales**; solo los usan los scripts `npm run dev|build` del scaffold de Vite, que la API no necesita

---

## Instalación local

```bash
git clone https://github.com/rubenminarro/synctaller-backend.git
cd synctaller-backend

composer install

cp .env.example .env
php artisan key:generate
```

`composer.json` también trae los scripts estándar de Laravel: `composer setup` (instala, crea `.env`, genera la clave,
migra y compila assets con npm), `composer dev` (servidor, cola, logs con Pail y Vite en paralelo; requiere npm) y
`composer test`.

Configurar en `.env` la base de datos y la contraseña de los usuarios de prueba:

```env
APP_NAME=SyncTaller
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=synctaller
DB_USERNAME=root
DB_PASSWORD=

# Contraseñas que el seeder asigna: al administrador principal y al resto de los usuarios de prueba
ADMIN_PASSWORD=
TEST_PASSWORD=

# Validar que el dominio de los emails exista (DNS); false solo sin red
EMAIL_DNS_CHECK=true

# Avisos a clientes: con "log" los emails se escriben en storage/logs/laravel.log
MAIL_MAILER=smtp
MAIL_HOST=<servidor SMTP>
MAIL_PORT=587
MAIL_USERNAME=<usuario>
MAIL_PASSWORD=<contraseña>
MAIL_FROM_ADDRESS=<remitente del taller>
MAIL_FROM_NAME="${APP_NAME}"
QUEUE_CONNECTION=database

# Datos del taller en los comprobantes y reportes PDF (config/company.php)
COMPANY_NAME="Taller Ejemplo"
COMPANY_LEGAL_NAME="Taller Ejemplo S.A."
COMPANY_TAX_ID=80000000-0
COMPANY_ACTIVITY="Reparación y mantenimiento de vehículos"
COMPANY_ADDRESS="Av. Ejemplo 123"
COMPANY_CITY=Asunción
COMPANY_PHONE="+595 21 000000"
COMPANY_EMAIL=taller@example.com
```

### Cola y tareas programadas (notificaciones)

Los emails a clientes se envían por cola y los recordatorios diarios (facturas, mantenimientos, cuentas por pagar) los
genera el scheduler (`notifications:send-reminders`, a la hora de `config/notifications.php`). En el servidor deben
correr dos procesos:

```bash
php artisan queue:work          # envía los avisos registrados (dejarlo corriendo, p. ej. como servicio)
php artisan schedule:run        # cada minuto; en Windows, con el Programador de tareas
```

En Windows: Programador de tareas → crear tarea que repita cada 1 minuto la acción
`php C:\laragon\www\synctaller-backend\artisan schedule:run`. Sin estos procesos los avisos quedan `pending` y los
recordatorios no se generan. En desarrollo, `composer dev` ya levanta la cola; los recordatorios se pueden probar a
mano con `php artisan notifications:send-reminders`.

### Base de datos (MySQL en Laragon)

1. Iniciar MySQL desde Laragon y crear la base `synctaller` (utf8mb4), por ejemplo desde HeidiSQL o con
   `CREATE DATABASE synctaller CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`.
2. Sobre una base **vacía**, migrar y cargar los datos iniciales y de ejemplo:

```bash
php artisan migrate --seed
php artisan serve
```

La API queda disponible en `http://127.0.0.1:8000/api`.

**Precauciones:**

- `php artisan migrate` (sin `--seed`) es lo que corresponde después de traer cambios: aplica solo las migraciones nuevas.
- `migrate:fresh`, `migrate:refresh` y `db:wipe` **borran todos los datos**. No usarlos sobre una base con datos que
  interesen.
- Todos los seeders son **idempotentes**: `php artisan db:seed` sobre una base ya sembrada no duplica ni falla
  (`tests/Feature/Seeders/DatabaseSeederTest`). Omiten lo que ya existe por clave natural: marca y modelo por nombre,
  cliente por documento, vehículo por placa, la visita de cada escenario, presupuesto si la recepción ya tiene uno, OT
  si el presupuesto ya tiene, factura si la OT ya tiene, repuestos por SKU, etc. Igual **agregan** los datos de ejemplo
  que falten, así que sobre una base con datos reales conviene correr solo los seeders necesarios.
- Los datos de ejemplo se crean **por los Services**, como si los cargaran los usuarios de cada rol, y con la fecha de
  cada evento repartida en los últimos tres meses (ver "Datos de prueba"). Sembrar una base vacía tarda unos 15 s.
- `RolesAndPermissionsSeeder` sincroniza roles y permisos; correrlo después de cada fase que agregue permisos:

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
```

- Si se cambia el `.env` y no se ve reflejado, verificar que no haya configuración cacheada (`php artisan config:clear`).

### Datos de prueba

**Usuarios** (`AdminUserSeeder`, `MechanicSeeder`). `admin@mail.com.py` usa `ADMIN_PASSWORD`; el resto, `TEST_PASSWORD`.
Qué puede hacer cada rol está en la sección 5 de [`CLAUDE.md`](CLAUDE.md).

| Rol | Emails (`@mail.com.py`) |
|---|---|
| administrador | `admin`, `admin2` |
| recepcionista | `recepcion1`, `recepcion2`, `recepcion3` |
| jefe-taller | `jefetaller1`, `jefetaller2` |
| cajero | `caja1`, `caja2` |
| deposito | `deposito1`, `deposito2` |
| mechanic | `jperez` (MEC-001), `cgomez` (MEC-002), `mecanico3`–`mecanico4` (activos), `mecanico5` (inactivo), `mecanico6` (de licencia) |

**Catálogos:** 33 clientes (uno sin email, `30111222`, uno dado de baja de los avisos, `30222333`, una empresa,
`80045678-1`, y los clientes empresa de las dos aseguradoras), 45 vehículos (uno
sin placa), 18 servicios (uno inactivo), 32 ítems de diagnóstico en el catálogo (uno inactivo; todos los ítems de los escenarios salen de él), 5 proveedores (uno inactivo), 18 repuestos (uno inactivo, uno descontinuado,
dos bajo el mínimo y el kit de embrague sin stock).

**Escenarios del taller** (`database/seeders/ExampleScenarios.php`): una recepción por escenario, llevada hasta su
estado final por los Services, con fechas en los últimos tres meses.

| Escenarios | Estado final |
|---|---|
| H01–H13 | OT completada, facturada y entregada: contado y crédito, pagadas, parciales, vencidas (H06, H07), una factura anulada y vuelta a emitir (H04), un cobro anulado (H05), una entrega anulada y vuelta a registrar (H03) |
| H14–H17 | OT completada sin entregar: pagada (H14), al contado sin cobrar (H15), factura en borrador (H16), sin facturar (H17) |
| C01–C03 | Recepción pendiente: sin diagnóstico, con diagnóstico pendiente, con diagnóstico en curso |
| C04, C06–C08, C15–C16 | Presupuesto en borrador, rechazado, rechazado y reabierto, cancelado; enviado a clientes que no reciben avisos (omitidos) |
| C05, C07 | Siniestro abierto con líneas a cargo de la aseguradora. C05: autorización en dos versiones (la segunda con un documento como evidencia), presupuesto aprobado y re-aceptado, y una excepción para iniciar sin autorización sin usar. C07: autorización invalidada al reabrir el presupuesto |
| C09, C14 | OT pendiente (C14 con repuestos reservados) |
| C10–C13 | OT en curso (C12 con un extra pendiente de la respuesta del cliente, C13 esperando un repuesto sin stock) o pausada (C11) |
| X01–X02 | Recepción cancelada (X02 con la OT cancelada) |

Además: dos aseguradoras (`InsurerSeeder`, una inactiva), siniestros cerrado (H07) y cancelado (X01) además de los
abiertos (`ClaimSeeder`) con sus documentos (`ClaimDocumentSeeder`); compras recibidas, anulada y en borrador, con cuentas por pagar vencidas y por vencer
(`PurchaseSeeder`, `SupplierPaymentSeeder`); ajustes de inventario (`InventoryAdjustmentSeeder`); mantenimientos al
día, por vencer y vencidos (`VehicleMaintenanceSeeder`); comisiones liquidadas de los meses cerrados
(`CommissionSeeder`); y avisos a clientes enviados, fallido, reenviado, omitidos y pendientes, con los avisos internos
de más de tres días leídos (`NotificationSeeder`). Nunca sale un email: el seeder usa el mailer `array`. Los avisos que
quedan pendientes se envían con `php artisan queue:work` (en desarrollo, `MAIL_MAILER=log`).

---

## Frontend

Este repositorio contiene **solo la API**. No hay código del frontend: `resources/` y `package.json` son el scaffold
por defecto de Laravel (Vite + Tailwind) y `routes/web.php` no define rutas.

El frontend es un repositorio aparte, `../synctaller-frontend` (React 19 + TypeScript + Vite + Tabler). Lo que está
preparado para él:

| Configuración | Valor actual |
|---|---|
| `config/cors.php` → `allowed_origins` | `http://localhost:5173` (servidor de desarrollo de Vite) |
| `config/cors.php` → `exposed_headers` | `Content-Disposition` (nombre de los PDF/CSV descargados) |
| `config/sanctum.php` → `stateful` (`SANCTUM_STATEFUL_DOMAINS`) | `localhost:5173` |
| URL base de la API para el frontend | `http://127.0.0.1:8000/api` (o el `APP_URL` + `/api`) |

La variable con la URL de la API (por ejemplo `VITE_API_URL`) pertenece al proyecto del frontend; este repositorio no
la usa. Si el frontend corre en otro origen, agregarlo en `config/cors.php`.

---

## Uso de la API

Todas las rutas (salvo `POST /api/login` y `GET /api/branding`) requieren el header `Authorization: Bearer {token}`. Todo `/api`
responde JSON con el formato de la API aunque no se envíe `Accept: application/json` (middleware global
`EnsureJsonResponse`); se recomienda enviarlo igual.

Autenticación con Sanctum: `POST /api/login` devuelve `data.token` y `data.user` (con roles); `GET /api/me` devuelve
el usuario autenticado (`data.user`), los nombres de sus permisos (`data.permissions`) y su ficha de mecánico, si
la tiene (`data.mechanic`), y sus preferencias visuales (`data.preferences`); `POST /api/logout` revoca
el token actual. Los tokens no expiran. Cada endpoint exige un permiso (`recurso.acción`) asignado al rol del usuario.

```http
POST /api/login
Content-Type: application/json

{ "email": "admin@mail.com.py", "password": "..." }
```

### Formato de respuesta

Éxito:

```json
{ "success": true, "status": 200, "message": "...", "data": {}, "errors": null, "meta": null, "timestamp": "..." }
```

En los listados `meta.pagination` trae `total`, `perPage`, `currentPage` y `lastPage`.

Error:

```json
{ "success": false, "status": 422, "message": "...", "data": null, "errors": null, "error_code": "WORK_ORDER_NOT_CANCELLABLE" }
```

- `message` está en español y es para el usuario.
- `error_code` es estable y es el que debe usar el frontend para decidir qué hacer.
- Los errores de negocio responden `422` (o `409` en conflictos), nunca `500`.

Códigos HTTP estándar: `400 BAD_REQUEST`, `401 UNAUTHENTICATED`, `403 FORBIDDEN`, `404 RESOURCE_NOT_FOUND`,
`405 METHOD_NOT_ALLOWED`, `406 NOT_ACCEPTABLE`, `415 UNSUPPORTED_MEDIA_TYPE`, `422 VALIDATION_ERROR`,
`429 TOO_MANY_REQUESTS`, `500 INTERNAL_SERVER_ERROR`, `503 SERVICE_UNAVAILABLE`. Nunca se devuelven trazas ni
detalles internos.

### Importes

- Los precios internos (presupuesto, OT, cierre) son **sin IVA**; la factura los expresa **con IVA incluido**.
- La facturación y los pagos usan **guaraníes enteros**.

---

## Tests

Los tests usan SQLite (no tocan la base de desarrollo):

```bash
php artisan test
# o
vendor/bin/phpunit
# o (limpia la config cacheada antes)
composer test
```

440 tests en `tests/Feature`, uno o más archivos por módulo. No necesitan internet: `phpunit.xml` desactiva la
consulta DNS de los emails (`EMAIL_DNS_CHECK=false`).

Usan una base SQLite en archivo, `database/testing.sqlite` (la crea `tests/bootstrap.php`; está en `.gitignore`):
`RefreshDatabase` migra y siembra roles y permisos **una vez por ejecución** (`Tests\TestCase::$seeder`) y cada test
corre en una transacción que se revierte. Con `:memory:` migraba y sembraba en cada test (la suite tardaba ~4 min; ahora
unos 3). Un test nuevo que necesite roles y permisos no tiene que sembrarlos. `phpunit.xml` además desactiva la
sincronización a disco de SQLite (`DB_SQLITE_SYNCHRONOUS=OFF`, `DB_SQLITE_JOURNAL_MODE=MEMORY`): la base es descartable.

`DatabaseSeederTest` siembra los datos de prueba una sola vez (y otra para la idempotencia), con la cola falseada
como en desarrollo, y verifica que cada rol y cada estado de los módulos quede cubierto.

Si `pdo_sqlite` no está habilitado en el `php.ini` de Laragon:

```bash
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit
```

Para pruebas manuales con Postman, seguir [`docs/testing/guia-postman.md`](docs/testing/guia-postman.md): está
organizada por fase y cada sección reutiliza los datos de la anterior.

---

## Arquitectura

```text
Controller (HasMiddleware: permiso por operación)
    ↓
Form Request (validación de entrada)
    ↓
Service (reglas de negocio, transacciones, cambios de estado)
    ↓
Model / Eloquent
    ↓
API Resource (Resource para listados, ShowResource para detalle)
```

| Carpeta | Contenido |
|---|---|
| `app/Http/Controllers/Api` | Endpoints (56 controllers) |
| `app/Http/Requests` | Validación |
| `app/Services` | Lógica de negocio (41 services) |
| `app/Models` | Modelos Eloquent |
| `app/Http/Resources` | Contrato JSON público |
| `app/Enums` | Estados y tipos |
| `app/Exceptions` | Excepciones de dominio (`AppException` y una por módulo, con `error_code`) |
| `app/Support/Decimal.php` | Redondeo con BCMath |
| `app/Traits/ApiResponse.php` | Respuestas estándar |
| `bootstrap/app.php` | Middlewares de permisos y mapeo de excepciones a JSON |
| `config/invoice.php`, `config/maintenance.php` | Parámetros de negocio (IVA de presupuestos y facturas, decimales, umbrales de mantenimiento) |
| `routes/api.php` | Rutas de la API (201) |
| `database/migrations`, `database/seeders` | Esquema y datos iniciales/de ejemplo |
| `tests/Feature` | Tests por módulo |
| `docs/` | Handoff y guía de Postman |

Convenciones principales:

- La lógica de negocio va en Services, no en Controllers.
- Los estados se modelan con Enums; no se permiten transiciones arbitrarias.
- Las operaciones sobre varios registros usan `DB::transaction` y `lockForUpdate` cuando hay concurrencia.
- Cálculos de dinero y cantidades con BCMath, nunca con `float`.
- Rutas anidadas con `scopeBindings()`; actualizaciones parciales con `PATCH`.
- Las migraciones no llaman a código de la aplicación.

---

## Flujo de trabajo

- No se trabaja directamente sobre `main`: cada fase o cambio va en su propia rama (`feature/...`, `fix/...`,
  `docs/...`, `test/...`) y se integra a `main` con merge manual.
- Cada fase actualiza `CLAUDE.md` (reglas vigentes), `docs/handoff.md` (decisiones e historia) y la guía de Postman.
- Antes de modificar código, leer [`CLAUDE.md`](CLAUDE.md): metodología, convenciones y reglas de negocio.

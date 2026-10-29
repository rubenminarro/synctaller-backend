# Spike T0 — `spatie/laravel-multitenancy` (informe)

Rama descartable `spike/t0-multitenancy` (desde `main` `2873ee5`). **No se mergea.** Condiciones: P2 y G de
`docs/especificacion-aseguradoras.html` (§3.13 y §10.2). Este informe se registra antes de instalar el paquete y se
completa a medida que avanza la evaluación.

## 1. Versión elegida (registrada antes de instalar, 2026-10-28)

| Versión | Publicada | ¿≥ 2 semanas? | Notas del changelog relevantes |
|---|---|---|---|
| 4.3.0 | 2026-10-26 | No | `--continue-on-error` en `tenants:artisan`. Sin cambios de compatibilidad |
| 4.2.3 | 2026-10-26 | No | Código de salida de `tenants:artisan` cuando falla un taller; MySQL 8.4 en el CI |
| 4.2.2 | 2026-10-19 | No | Workers de larga vida (sobre todo Octane): limpiar el estado del taller y restaurar rutas del landlord; deshacer un cambio de taller fallido a medias; listeners de cola registrados una sola vez. Requiere PHP ^8.3 y Laravel ^12 o ^13 |
| **4.2.1** | **2026-09-22** | **Sí** | Arreglo de documentación; `TenantAware` devuelve éxito sin talleres. Incluye lo de la 4.2.0 (2026-08-07): restaura el contexto del taller después de jobs `sync` y `dispatchSync()`, y `Tenant::current()` queda vacío entre jobs en el worker |

**Elegida: 4.2.1, fija (`"spatie/laravel-multitenancy": "4.2.1"`).** Es el último parche de la rama 4.2 con al
menos dos semanas publicado. Lo que corrige la 4.2.2 es robustez en workers de larga vida, sobre todo con Octane (no
se usa: Nginx con php-cgi y `queue:work`). No es una corrección de seguridad ni de compatibilidad con este stack, así
que no se justifica la excepción de P2.

Si S7 (worker A/B/A) o S8 (base caída) fallan por algo que corrige la 4.2.2, la evaluación se detiene y se consulta
antes de cambiar de versión.

Fuentes: `composer show -a spatie/laravel-multitenancy` y el `CHANGELOG.md` del repositorio del paquete, consultados
el 2026-10-28.

## 2. Línea base de rendimiento (S13, antes de instalar)

Medida el 2026-10-28 en la notebook de desarrollo, sin el paquete, en `main` (`2873ee5`) y con la configuración
existente: Nginx de Laragon con `php-cgi`, PHP 8.3.16 y MySQL local. Requests secuenciales con conexión nueva cada una
(cURL desde un script PHP), 5 de calentamiento, 60 muestras por endpoint (30 en el login), autenticadas como el
administrador. Dos corridas para estimar la variación entre corridas.

| Endpoint | Corrida 1: p50 / p95 (ms) | Corrida 2: p50 / p95 (ms) | Diferencia de p95 entre corridas |
|---|---|---|---|
| `GET /api/me` | 1005 / 1111 | 995 / 1097 | 14 |
| `GET /api/work-orders` | 1243 / 1416 | 1214 / 1318 | 98 |
| `GET /api/work-orders/{id}` | 1088 / 1270 | 1069 / 1256 | 14 |
| `GET /api/reports/sales` | 901 / 1018 | 934 / 1070 | 52 |
| `GET /api/branding` (público) | 741 / 826 | 756 / 912 | 86 |
| `POST /api/login` (público) | 1546 / 1597 | 1615 / 1772 | 175 |

**Suite:** 393 tests en 334,5 s (última corrida en este equipo con el mismo código). El umbral G de la suite se
fijó sobre la línea base de la otra notebook (406 s, límite 487,2 s). Medido en este equipo, el límite equivalente
(+20 %) es **401,4 s**.

### Observaciones de la medición

* **OPcache está desactivado** en el PHP de Laragon (`;opcache.enable=1` en el `php.ini`). El tiempo es casi todo del
  servidor: DNS y conexión suman menos de 20 ms y el primer byte llega a unos 0,78 s incluso en `branding`. Cada request
  compila el framework entero.
* **Ruido mayor que los umbrales:** entre dos corridas del mismo código, el p95 varió de 14 a 175 ms. El umbral de G
  para los públicos (≤ 15 ms) queda por debajo del ruido y no se puede evaluar con esta configuración. El de los
  autenticados (máximo entre 15 ms y el 10 % de la base) da unos 100–140 ms, del mismo orden que el ruido.
* Por `127.0.0.1` aparecieron dos esperas de 10 y 22 s, que parecen procesos `php-cgi` ocupados. Por el nombre del
  sitio no se repitió. Es un comportamiento del entorno local, no del código.
* Los tokens creados por la medición se borraron. Los logins quedan en `audit_logs`, que no se borra (36 por corrida).

**Pendiente de decisión (G):** repetir antes y después con OPcache activado, para que S13 sea evaluable. Cambia el
`php.ini` local, no el repositorio.

## 3. Lo que se armó en la rama (cambio mínimo)

| Archivo | Cambio |
|---|---|
| `composer.json` / `composer.lock` | `spatie/laravel-multitenancy` **4.2.1** fijo. Única dependencia nueva |
| `config/multitenancy.php` | Publicado. Tareas: `SwitchTenantDatabaseTask`, `PrefixCacheTask` y una propia (`ForgetPermissionsCacheTask`). La conexión del taller es la de siempre (`DB_CONNECTION`: `mysql`, `sqlite` en los tests); la central es `landlord`. Sin `tenant_finder` al arrancar (ver §4, hallazgo 1) |
| `config/database.php` | Conexiones `landlord` (MySQL, base `synctaller_landlord`) y `landlord_testing` (SQLite en archivo) |
| `config/queue.php` | `failed_jobs` en la conexión de `DB_QUEUE_FAILED_CONNECTION` (central) |
| `app/Http/Middleware/IdentifyTenant.php` | Propio. En el grupo `api`, antes de `auth:sanctum`. Busca el taller por host (en `local`/`testing`, también por la cabecera `X-Tenant`) con el `DomainTenantFinder` del paquete. Responde `404 TENANT_NOT_FOUND` y `503 TENANT_UNAVAILABLE` con el contrato JSON |
| `app/Multitenancy/ForgetPermissionsCacheTask.php` | Propia. Olvida los permisos de Spatie en memoria y vuelve a tomar la cache con el prefijo nuevo |
| `database/migrations/landlord/` | `tenants` (estructura del stub del paquete) y `spike_probes` (solo para S7) |
| `tests/TestCase.php`, `tests/bootstrap.php` | Base central SQLite de los tests. Cada test activa el taller del host de `APP_URL` antes de que `RefreshDatabase` abra su transacción |
| `app/Jobs/SpikeProbeJob.php` | Solo para la prueba de S7, S8 y S9 |
| Entorno de la prueba | `DB_QUEUE_CONNECTION`, `DB_QUEUE_FAILED_CONNECTION` y `DB_CACHE_CONNECTION` = `landlord`. Bases MySQL locales: `synctaller_landlord` (talleres, `jobs`, `failed_jobs`, `cache`), `synctaller` como taller A, `synctaller_b` como taller B (migrada, con roles y un administrador) y el taller C apuntando a una base inexistente |

## 4. Resultados S1–S12

| # | Resultado | Evidencia |
|---|---|---|
| S1 | **Cumple** | `composer require` agrega solo el paquete: `spatie/laravel-package-tools` 1.93.3 ya estaba (la usa `spatie/laravel-permission`). Sin cambios en `laravel/framework` 13.38.0, `laravel/sanctum` 4.3.3 ni `spatie/laravel-permission` 8.3.2 |
| S2 | **Cumple** | **393 tests (6139 aserciones) en verde** con el paquete instalado y un taller activo en `TestCase`, en 387,7 s (ver S13) |
| S3 | **Cumple** | Por Nginx contra MySQL: el host de A responde 200. Un host desconocido da `404 TENANT_NOT_FOUND` con el contrato JSON (`EnsureJsonResponse` corre antes) |
| S4 | **Cumple** | Token de A en el host de B → 401. Token de B en A → 401. Credenciales de A en B → 401. Token de B en B → 200 con su usuario |
| S5 | **Cumple** | Desde B: `exists` con ids de A → 422 (`client_id`, `vehicle_id`, categoría); binding anidado con `scopeBindings` → 404; el SQL crudo de `ReportService` (ventas) devuelve 0 en B y los datos propios en A |
| S6 | **Cumple** | Ningún modelo cambió: usan la conexión por defecto, que el paquete apunta a la base del taller. Archivos tocados: los de la sección 3 |
| S7 | **Cumple, con la cache en la central** | Un solo worker procesó, en orden, jobs despachados con `afterCommit` en A, B, A, B, A, C, A. Cada uno corrió en su taller (base, prefijo de cache `tenant_id_1`/`tenant_id_2` y valor de cache propio). El job de una transacción revertida no se encoló. El taller viaja en el `Context` de Laravel (`tenantId`). **Con la cache en la base del taller el worker se cae** después del primer job (hallazgo 3) |
| S8 | **API: cumple. Jobs: no cumple** | El host de un taller con la base caída da `503 TENANT_UNAVAILABLE` sin detalles (ni `SQLSTATE` ni el nombre de la base). Pero su job falla en el primer intento: consume el único intento (`$tries = 1`) y va a `failed_jobs` (central). El worker sigue con los demás talleres. El paquete no libera el job: hace falta un middleware de job propio y otra política de reintentos (ver S12) |
| S9 | **Cumple** | En B se quitó `invoices.index` al rol cajero. En el mismo worker, alternando, A vio 36 permisos (y el permiso) y B 35 (sin el permiso), siempre. Requiere la tarea propia `ForgetPermissionsCacheTask`: Spatie guarda los permisos en memoria del proceso |
| S10 | **No cumple con 4.2.1** | `tenants:artisan "migrate --force"`: la falla del primer taller corta el comando (código 1) y **los demás no se migran**. La 4.3.0 agrega `--continue-on-error`, pero no es elegible todavía (P2). Hace falta el comando propio `tenants:migrate` previsto en §3.8, con registro por taller |
| S11 | **Cumple, en un solo proceso** | `tenants:artisan "notifications:send-reminders"` corre por taller: A registró 2 avisos de facturas y 2 internos, B ninguno. Los 2 `SendClientNotification` se encolaron en la central con el taller A y el worker los envió en A. Corre en serie: la falla de un taller detiene el resto (como en S10). "Un job por taller" (§3.5) es código propio |
| S12 | **Informe** | Ver la tabla siguiente |

### Lo que el paquete no cubre (S12)

| Pieza | Código propio estimado |
|---|---|
| Identificación con errores JSON, `X-Tenant` local y `503` | Middleware de ~40 líneas (hecho en el spike) |
| Permisos de Spatie entre talleres | Tarea de ~25 líneas (hecha) |
| Cache, `jobs` y `failed_jobs` en la central | Configuración (hecha en el spike con variables de entorno) |
| Job que no consume el intento con la base del taller caída (S8) | Middleware de job y cambiar `$tries = 1` por `retryUntil` en `SendClientNotification` |
| Migraciones por taller que siguen ante una falla, con registro (S10) | Comando `tenants:migrate` (§3.8) |
| Scheduler con un job por taller (S11) | Comando que despacha un job por taller activo |
| Storage por taller, configuración del taller (`company.*`, IVA), suspensión y ciclo de vida | T1, T4 y T5, como estaba previsto |
| Modelo `Tenant` propio | El del paquete no admite asignación masiva (`create()` falla): conviene extenderlo con `status`, `fillable` y los campos de §3.1 |

### Hallazgos

1. **El paquete identifica el taller al arrancar la aplicación, no en un middleware.** Lo hace solo fuera de la
   consola (nunca en los tests) y, si no encuentra el taller, dispara un evento sin responder 404. No respeta el orden
   de §3.2 (después de `EnsureJsonResponse`) ni el contrato de errores. Se usó el finder del paquete desde un
   middleware propio, con el finder de arranque desactivado. Es el acoplamiento acotado de P2.
2. **En los tests, el taller se activa en `setUpTraits()`:** `RefreshDatabase`, que usan todos los tests, pisa
   `beforeRefreshingDatabase()` si se define en `TestCase` (un método del trait gana sobre uno heredado). Activar un
   taller purga la conexión, así que tiene que ser antes de que `RefreshDatabase` abra la transacción.
3. **La cache tiene que estar en la base central.** Con `CACHE_STORE=database` en la conexión del taller, el worker se
   cae al terminar el primer job: el paquete olvida el taller, la conexión queda sin base, y el worker consulta en la
   cache la señal de reinicio (`illuminate:queue:restart`). Con la cache en la central, con prefijo por taller (§3.6),
   funciona.
4. **`failed_jobs` también va en la central:** en la base del taller no se podría registrar el fallo de un taller caído.
5. **Entorno local:** Laragon corre dos procesos `php-cgi`, que se cierran solos cada 500 requests
   (`PHP_FCGI_MAX_REQUESTS`). Mientras Laragon los relanza, Nginx responde 502. Afecta las mediciones largas, no al
   spike. Matar los procesos a mano no los relanza: se reiniciaron con los mismos parámetros (o desde Laragon).

## 5. Rendimiento (S13)

La línea base de la sección 2 resultó **no comparable**: se midió con procesos `php-cgi` ya gastados, y la latencia
dependía más de la antigüedad del proceso que del código (`/api/me`: ~1000 ms con procesos viejos, ~360 ms con
procesos nuevos, mismo código). Se repitió de forma controlada el 2026-10-29: el código de `main` sin el paquete
(`git stash` y `composer install`) y el del spike, cada uno con los dos `php-cgi` recién reiniciados, una corrida de
60 muestras cada uno (30 en el login). OPcache siguió desactivado, como pide G.

| Endpoint | Base p50 / p95 (ms) | Spike p50 / p95 (ms) | Sobrecarga p50 | Umbral G (p95) |
|---|---|---|---|---|
| `GET /api/me` | 354 / 448 | 399 / 666 | +45 | ≤ 45 |
| `GET /api/work-orders` | 428 / 1060 | 514 / 1208 | +87 | ≤ 106 |
| `GET /api/work-orders/{id}` | 1020 / 2067 | 562 / 723 | −458 | ≤ 207 |
| `GET /api/reports/sales` | 407 / 1111 | 393 / 543 | −14 | ≤ 111 |
| `GET /api/branding` (público) | 316 / 582 | 301 / 398 | −15 | ≤ 15 |
| `POST /api/login` (público) | 580 / 832 | 580 / 612 | 0 | ≤ 15 |

**Lectura:** el ruido es mayor que cualquier efecto. El detalle de la OT dio 458 ms *más rápido* con el spike, y
entre corridas del mismo código el p95 varía hasta un segundo. Las diferencias de p50 van de −15 a +87 ms sin un
patrón: los públicos no cambian y los autenticados suben algo, que sería lo esperable (la consulta del taller a la
central más la conexión a su base). No se puede afirmar que se cumplan ni que se superen los umbrales de 15 ms.
**S13 queda sin evaluar con esta configuración.** Como pide G, superar un umbral no descarta el paquete: obliga a
medir mejor.

**Suite:** 393 tests en 387,7 s con el spike contra 334,5 s sin él (**+15,9 %**). Cumple el límite de G (487,2 s,
fijado con la línea base de la otra notebook) y el equivalente en este equipo (401,4 s). Esa corrida coincidió con
trabajo en paralelo contra MySQL, así que el valor es pesimista.

### Con OPcache activado (2026-10-29)

Se activó OPcache para `php-cgi` en el `php.ini` local, solo dos líneas: `zend_extension=opcache` y
`opcache.enable=1`. `opcache.enable_cli` sigue apagado, así que los tests y `artisan` no cambian. Copia del archivo
original: `php.ini.bak-antes-opcache-2026-10-29`. Se midió alternando base y spike dos veces, cada corrida con los
`php-cgi` recién reiniciados. `branding` bajó de unos 750 ms a unos 120 ms.

| Endpoint | Ronda 1: base → spike, p50 / p95 (ms) | Ronda 2: base → spike, p50 / p95 (ms) | Sobrecarga ronda 2 (p50 / p95) | Umbral G (p95) |
|---|---|---|---|---|
| `GET /api/me` | 180 / 339 → 209 / 269 | 152 / 181 → 180 / 216 | +28 / +36 | ≤ 18 |
| `GET /api/work-orders` | 237 / 329 → 309 / 625 | 205 / 235 → 252 / 317 | +47 / +82 | ≤ 23 |
| `GET /api/work-orders/{id}` | 180 / 220 → 221 / 260 | 169 / 223 → 205 / 256 | +35 / +32 | ≤ 22 |
| `GET /api/reports/sales` | 132 / 301 → 151 / 180 | 105 / 137 → 137 / 174 | +32 / +37 | ≤ 15 |
| `GET /api/branding` (público) | 166 / 208 → 112 / 154 | 73 / 114 → 105 / 138 | +31 / +23 | ≤ 15 |
| `POST /api/login` (público) | 771 / 871 → 396 / 520 | 352 / 375 → 780 / 949 | — | ≤ 15 |

**Resultado: S13 no cumple los umbrales de G.** Con OPcache el ruido baja y se ve un efecto consistente: el spike
agrega unos **30–45 ms por request** en los endpoints autenticados (p50 +19 a +72 en las dos rondas), por encima del
umbral (máximo entre 15 ms y el 10 % de la base: 15–23 ms). En `branding`, la ronda 1 dio −54 ms y la 2 +31 ms: no
concluyente, pero compatible con el mismo costo. El login no se puede evaluar: lo domina bcrypt y varía ±400 ms entre
corridas del mismo código.

**Causas probables, a investigar (G obliga a investigar y mitigar, no descarta):**

1. Una conexión nueva a la base central en cada request, solo para buscar el taller. En Windows, `DB_HOST=localhost`
   puede sumar la resolución IPv6→IPv4 en cada conexión; `127.0.0.1` lo evitaría.
2. La consulta del taller por dominio sin cache. Se podría guardar el taller por host en una cache local (APCu o
   archivo) con invalidación al editar el taller.
3. Las tareas de cambio de taller en cada request: `PrefixCacheTask` recrea el gestor de cache y
   `ForgetPermissionsCacheTask` vacía los permisos en memoria. En un request web (un proceso, un taller) la segunda
   sobra: solo hace falta en los workers.
4. La conexión anticipada (`getPdo()`) del middleware no debería costar: la primera consulta la abriría igual.

**Siguiente paso de S13:** aplicar 1 a 3 en la rama de T2 y volver a medir igual (OPcache, procesos frescos, base y
spike alternados), documentando el efecto de cada mitigación. La medición local compara antes y después, pero no
garantiza el rendimiento en producción.

## 6. Conclusión y recomendación

* **Ningún criterio crítico falló:** S1, S2, S4, S5 y S7 se cumplen. S7 se cumple con la cache en la base central, que
  ya era el diseño de §3.6.
* **No se cumplen con la 4.2.1, y se resuelven con código propio ya previsto en la especificación:**
  * **S8 (jobs):** un job no se libera sin consumir su intento cuando la base del taller está caída.
  * **S10:** las migraciones se cortan en el primer taller que falla.
* **S13 no cumple:** con OPcache, el spike agrega unos 30–45 ms por request autenticado, por encima de los umbrales de
  G (15–23 ms). Hay mitigaciones concretas para probar (sección 5).
* **El paquete cubre bien** el cambio de base, el prefijo de cache, el `Context` del taller en los jobs (también
  `sync` y `afterCommit`) y la ejecución de comandos por taller. Todo lo demás es código propio y chico: el middleware
  de identificación con errores JSON, los permisos de Spatie, la central para cache y `failed_jobs`, y lo de S8 y S10.
  Se concentra en pocos puntos, como pide P2: ningún modelo, controller ni service de negocio cambió.

**Recomendación:** el paquete es viable para la línea T. Antes de aprobar la dependencia (decisión aparte, P2):

1. Aplicar las mitigaciones de S13 (sección 5) y volver a medir. Si no bajan al umbral, decidir si se acepta el costo
   o si se ajustan los umbrales: G es una decisión tuya y la seguridad prevalece.
2. Revisar la versión cuando la 4.2.3 o la 4.3.0 cumplan dos semanas (desde el 2026-11-09): la 4.3.0 trae
   `--continue-on-error`, que resolvería parte de S10.
3. Definir el modelo `Tenant` propio (`status`, `fillable`) y la suite de aislamiento en PHPUnit (§3.12). En el spike,
   S3–S5 y S7–S11 se verificaron con scripts contra MySQL por Nginx, no con tests del repositorio.

Esta rama no se mergea. `stancl/tenancy` y la solución propia quedan como alternativas, sin evaluar.

## 7. Cierre del spike y estado del entorno local (2026-10-29)

* **Código:** la rama `spike/t0-multitenancy` se descartó sin commitear, como pide P2. Este informe es lo único que
  pasó a `main`. El detalle de lo que se armó está en la sección 3, para retomarlo en T2.
* **`vendor/`:** `composer install` lo volvió al lock de `main`, sin el paquete.
* **Bases MySQL del spike:** `synctaller_landlord` y `synctaller_b` se eliminaron. `synctaller` no se tocó salvo lo
  siguiente.
* **En `synctaller` (taller A):** los recordatorios del 2026-10-29 se corrieron una vez por S11 (2 avisos de facturas
  vencidas, enviados al mailer `log`, y 2 internos). Las mediciones dejaron logins en `audit_logs`, que no se borra.
  Los tokens de las mediciones se borraron.
* **OPcache:** queda activado para `php-cgi`. Acelera el entorno de desarrollo unas 5 veces, y con
  `validate_timestamps` por defecto los cambios de código se ven enseguida. Para volver atrás, restaurar
  `php.ini.bak-antes-opcache-2026-10-29`, en la carpeta de PHP de Laragon.
* **Laragon:** los `php-cgi` quedaron corriendo, lanzados a mano con los mismos parámetros. Un Stop/Start desde
  Laragon los vuelve a su manejo normal.

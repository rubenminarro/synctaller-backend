# Continuidad — cambio de notebook (2026-10-27)

Punto de continuidad de SyncTaller para retomar el trabajo en otro equipo. Registra el estado verificado, las
decisiones tomadas, los documentos producidos y el siguiente paso acordado.

> **Alcance de este documento.** No reemplaza a [`CLAUDE.md`](../CLAUDE.md) (reglas vigentes) ni a
> [`handoff.md`](handoff.md) (historia y decisiones por fase), que **no** se modificaron en esta tarea. El diseño del
> módulo de aseguradoras todavía no está en esos documentos: la especificación de trabajo vigente es
> [`especificacion-aseguradoras.html`](especificacion-aseguradoras.html) (versión 2, aprobada; sección 6). Las páginas
> publicadas de la sección 6 quedan como antecedente.

Convención: **[verificado]** = comprobado en el código o el entorno el 2026-10-27; **[pendiente]** = sin verificar o
sin decidir.

---

## 1. Proyecto, stack y estado actual

| Tema | Estado | Fuente |
|---|---|---|
| Proyecto | SyncTaller (antes Presupuestador): gestión de talleres mecánicos | `CLAUDE.md` §1 |
| Backend | Laravel **13.38.0** [verificado: `php artisan --version`], PHP `^8.3`, Sanctum 4.3.3, spatie/laravel-permission 8.3.2, dompdf `^3.1` [verificado: `vendor/composer/installed.json`, `composer.json`] | — |
| Base de datos | MySQL en Laragon, base `synctaller`, conexión `mysql`. Tests en SQLite (`database/testing.sqlite`) | `CLAUDE.md` §1 y §3 |
| Servidor local | Nginx de Laragon con `php-cgi` (no Apache) | `CLAUDE.md` §3 |
| Frontend | Repositorio aparte `../synctaller-frontend` (React 19 + TypeScript + Vite + Tabler) | `CLAUDE.md` §1 |
| Estado funcional | Roadmap FASES 10–25 implementado, más preferencias, logo y catálogo de ítems de diagnóstico | `CLAUDE.md` §1 |
| Tests | **374 tests (5985 aserciones) en verde, en 406 s** [verificado: `php artisan test` ejecutado el 2026-10-27 en la sesión que retomó desde este documento] | `CLAUDE.md` §1 |
| Deuda técnica | Handoff §14 **#13 (clientes aseguradoras) abierta** desde el 2026-10-24. `CLAUDE.md` §7 dice "Sin deudas pendientes": es una discrepancia sin corregir | `docs/handoff.md:4003` |

Nota: en algunos pedidos se mencionó "Laravel 12"; el código usa Laravel 13 desde el 2026-10-24 (handoff 2.51).

---

## 2. Repositorios y ramas

| Repositorio | Remoto | Rama | Commit | Estado [verificado el 2026-10-27] |
|---|---|---|---|---|
| Backend | `https://github.com/rubenminarro/synctaller-backend.git` | `main` | `27803b1` (merge de este documento; actualizado por su corrección) | Igual a `origin/main`, sin cambios pendientes |
| Frontend | `https://github.com/rubenminarro/synctaller-frontend.git` | `main` | `cbbb559` ("Elige siempre del catálogo el ítem del diagnóstico") | Sin cambios pendientes |

* No hay ramas abiertas para el módulo de aseguradoras: no se escribió código del módulo.
* La rama remota `docs/deuda-aseguradoras` (`d48c38a`) ya está integrada en `main` (registró la deuda #13).

---

## 3. Estado de Git y `composer.lock`

### 3.1 `composer.lock` (E0 resuelto: se descartaron los cambios locales)

* El 2026-10-27, a pedido del usuario, se descartaron con `git checkout -- composer.lock` los cambios locales sin
  commitear. Eran solo versiones de parche (guzzle 8.3.0 → 8.3.1, masterminds/html5 2.11.0 → 2.12.0, nesbot/carbon
  3.15.0 → 3.15.1, vlucas/phpdotenv 5.7.0 → 5.8.0; `composer.json` intacto).
* El lock commiteado es el de `9ca9faf` (2026-10-24).
* **Notebook nueva:** `composer install` instala exactamente las versiones del lock commiteado. No hace falta nada
  más.
* **Notebook anterior** [pendiente si se sigue usando]: `vendor/` quedó con las versiones nuevas y no coincide con el
  lock. Correr `composer install` para alinearlo.
* Actualizar las dependencias queda para una tarea aparte (`chore/`), con la suite completa.

### 3.2 Este documento

Está en `main` desde el merge `27803b1`, vía la rama `docs/continuidad-cambio-notebook`. Esta corrección se mergea
desde `docs/continuidad-composer-lock`.

---

## 4. Documentos y código consultados

* `CLAUDE.md` completo; `docs/handoff.md` (§1, §2.24–2.53, §9–§14); `README.md` (índice).
* `../synctaller-frontend`: `src/features/clients/api.ts`, `src/features/invoices/api.ts`,
  `src/features/work-orders/api.ts`, `src/services/api.ts`.
* Backend (lectura):
  * **Services:** `InvoiceService`, `BudgetService`, `WorkOrderService`, `WorkOrderClosingService`, `PaymentService`,
    `VehicleDeliveryService`, `NotificationReminderService`, `ReportService`, `VehicleHistoryService`,
    `DocumentPdfService`, `ClientNotificationService`, `WorkOrderPartService`.
  * **Modelos:** `Invoice`, `InvoiceItem`, `Reception`, `Client`, `WorkOrder`, `WorkOrderPart`.
  * **Migraciones:** `000005`, `000009`, `000017`, `000018`, `000038`–`000041`, `000051`.
  * **Requests y controllers:** `StoreClientRequest`, `UpdateInvoiceRequest`, `BudgetController`,
    `InvoiceController`, `ClientController`.
  * **Recursos:** `ShowWorkOrderResource`, `VehicleHistoryReceptionResource`.
  * **Otros:** `RolesAndPermissionsSeeder`, `Auditable`, `AuditEvent`, `AppServiceProvider`, `tests/TestCase.php`,
    `phpunit.xml`.
  * **Configuración:** `config/permission.php`, `filesystems.php`, `queue.php`, `sanctum.php`, `company.php`.
  * **Rutas:** `routes/api.php`, `routes/console.php`.
* Metadata de Packagist (solo lectura): `stancl/tenancy` y `spatie/laravel-multitenancy`.

---

## 5. Decisiones del módulo de aseguradoras y siniestros

### 5.1 Aprobadas conceptualmente por el usuario

Son la dirección del diseño, **no** una autorización para modificar código.

1. **SaaS:** base central de plataforma y base de datos independiente por taller.
2. **Clientes:** `Client` admitirá personas físicas y empresas. `Insurer` será una entidad propia vinculada a un
   `Client` para facturación y cuentas por cobrar.
3. **Reparto económico:** independiente del cierre de la OT, con versiones e historial; permite facturar por
   responsable económico.
4. **Autorizaciones:** la aprobación del cliente, la autorización de la aseguradora y la excepción interna son
   registros independientes.
5. **MVP:** catálogo de aseguradoras, siniestros, documentos privados, autorizaciones por línea, complementarios,
   deducible como importe, reparto económico y facturación dividida.
6. **Compatibilidad:** preservar el flujo de clientes particulares, los contratos actuales de la API y los datos
   existentes.
7. **Fiscalidad:** no inventar reglas tributarias. El deducible y las demás cuestiones fiscales se validan con
   profesionales en Paraguay.

### 5.2 Decisiones A–G y P1–P9: aprobadas el 2026-10-27

Se revisaron y aprobaron una por una el 2026-10-27, varias con precisiones. **El texto aprobado está en §10.2 de
[`especificacion-aseguradoras.html`](especificacion-aseguradoras.html)** y se aplica en las secciones que allí se
indican; las tablas siguientes quedan solo como registro de las propuestas originales. Aprobarlas no resuelve las
validaciones externas (sección 8).

**De la especificación (§10.2):**

| # | Propuesta | Recomendación |
|---|---|---|
| P1 | `composer.lock` | **Resuelto el 2026-10-27:** el usuario eligió descartar los cambios locales (sección 3.1) |
| P2 | Paquete de tenancy | Spike con `spatie/laravel-multitenancy` 4.2.1 |
| P3 | `first_name`/`last_name` | Nulos solo para empresas |
| P4 | Correlativos no fiscales | Aceptar huecos |
| P5 | Extras | Siempre a cargo del dueño en el MVP |
| P6 | Corregir un reparto | Exige anular las facturas vigentes |
| P7 | Tope de autorización | Autorizado ≤ presupuestado |
| P8 | Fotos y firmas públicas | Pasarlas a privado en una decisión aparte |
| P9 | Taller suspendido | Bloqueo total |

**De la revisión de la especificación:**

| # | Propuesta |
|---|---|
| A | El MVP factura de punta a punta solo siniestros con deducible 0; con deducible mayor a 0, la facturación espera la validación fiscal |
| B | Control estricto de la aceptación del dueño en el flujo de siniestros |
| C | Eliminar la excepción `exceed_authorization` |
| D | El cliente vinculado a una aseguradora nace con `notifications_enabled = false` |
| E | Disco privado dedicado sin `serve` para los documentos |
| F | Clave `invoice` legado + `invoices[]` + `invoices_count` en la OT |
| G | Umbral de sobrecarga aceptable para el spike (lo define el usuario) |

---

## 6. Auditorías y especificaciones realizadas

Las cuatro primeras son **páginas privadas en claude.ai** de la cuenta del usuario. Se abren desde cualquier equipo
con esa cuenta y **no están en el repositorio**. La quinta, la especificación de trabajo vigente, **sí está en el
repositorio**.

| Documento | Contenido | Ubicación |
|---|---|---|
| Estudio previo: "Aseguradoras y siniestros en SyncTaller" | Auditoría del flujo actual, limitaciones L1–L14, escenarios, modelo conceptual, fases | https://claude.ai/artifact/54TDFLL6nRUoizKYyEYSED |
| Informe de decisiones: "Decisiones del módulo de aseguradoras" | Multi-tenant (recomendación C), clientes y aseguradoras, reparto, autorizaciones, MVP, preguntas G | https://claude.ai/artifact/6NQ2MEHFyC4S2pUpgWensC |
| Especificación técnica: "Especificación del módulo de aseguradoras" | Arquitectura SaaS, entidades, flujos, invariantes I1–I8, API, permisos, plan E/T/M | https://claude.ai/artifact/R1BmU5AE8gYb6VQanTaer4 |
| Revisión: "Revisión de la especificación de aseguradoras" | Hallazgos H1–H15, invariantes corregidas R1–R10, ejemplos numéricos, criterios del spike S1–S13, decisiones A–G | https://claude.ai/artifact/T1j1B68eKpFZLrub5Jw3vx |
| **Especificación de trabajo (versión 2, aprobada)** | La especificación técnica con los 14 cambios de la revisión, las decisiones A–G y P1–P9 aprobadas (§10.2) y las validaciones externas pendientes G1–G10 y V1–V14 (§10.1) | [`docs/especificacion-aseguradoras.html`](especificacion-aseguradoras.html) (repositorio) |

* Las copias HTML de las cuatro páginas están en la carpeta temporal de la sesión de esta notebook
  (`%LOCALAPPDATA%\Temp\claude\...\scratchpad\aseguradoras-*.html`). Esa carpeta **no viaja** al otro equipo.
* Los archivos `Aseguradoras y siniestros en SyncTaller.md` y `Decisiones del módulo de aseguradoras.md` que se
  citaron en un pedido **no existen** en el repositorio [verificado]. Si existen en otro lugar, registrar su
  ubicación.
* **La página publicada de la especificación (`R1BmU5AE…`) sigue siendo la versión anterior**, sin las correcciones de
  la revisión. La versión vigente es `docs/especificacion-aseguradoras.html`, que las incorpora junto con las
  decisiones aprobadas. **Publicarla en esa página está pendiente de autorización.**
* **Revisión visual de los diagramas (2026-10-27):** los siete diagramas de la versión 2 se renderizaron y revisaron en
  una vista previa temporal (Mermaid 11.16.1, la misma versión y configuración de la página publicada; modo claro y
  oscuro). Falta comprobarlos en claude.ai después de publicar.
* El archivo del repositorio es la fuente de la página, sin `<!doctype>` ni el script de Mermaid (los agrega la
  publicación): abierto directamente en un navegador, los diagramas se ven como texto.

---

## 7. Arquitectura SaaS: criterios a respetar

Resumen; el detalle está en la especificación §3 y en la revisión §9.

* **Base central:** talleres, dominios, registro de migraciones, cola de jobs, usuarios de plataforma y, más
  adelante, el directorio de aseguradoras. **Base por taller:** el esquema actual completo, sin `tenant_id`.
* **Identificación:** por host, antes de `auth:sanctum` y después de `EnsureJsonResponse`. Errores propuestos:
  `TENANT_NOT_FOUND` (404), `TENANT_SUSPENDED` (403), `TENANT_UNAVAILABLE` y `TENANT_MAINTENANCE` (503).
* **Tokens:** los de Sanctum quedan en cada base de taller.
* **Jobs:** llevan el taller en el payload y restauran y limpian su contexto. El scheduler despacha un job por
  taller.
* **Aislamiento:** cache y permisos de Spatie con prefijo por taller; configuración del taller (`company.*`, IVA)
  en `system_settings`; archivos bajo `tenants/{id}`, con los documentos en un disco privado sin URL firmada.
* **Paquetes:** `stancl/tenancy` 3.10.1 y `spatie/laravel-multitenancy` 4.2.1 **declaran** Laravel 13 en Packagist.
  **No están verificados** en el proyecto. No instalar nada sin aprobación; primero el spike con los criterios
  S1–S13 de la revisión.
* **Orden:** la infraestructura SaaS (línea T) no bloquea el módulo (línea M), porque las tablas del taller no
  cambian. Debe estar terminada antes del primer taller comercial.

---

## 8. Validación externa pendiente (G1–G10 y V1–V14)

Ninguna está respondida. Además de G1–G10, la revisión de las decisiones agregó las preguntas V1–V14 (§10.1 de
`docs/especificacion-aseguradoras.html`), también pendientes.

1. **G1.** Tratamiento fiscal del deducible.
2. **G2.** Destinatario de las facturas, también en siniestros de tercero.
3. **G3.** Retenciones de las aseguradoras.
4. **G4.** Anticipos.
5. **G5.** Notas de crédito y SIFEN.
6. **G6.** Autorización por línea o por importe.
7. **G7.** Tarifas, descuentos y repuestos suministrados por la aseguradora.
8. **G8.** Forma y plazo de liquidación.
9. **G9.** Coaseguro.
10. **G10.** Plazo legal de conservación de documentos fiscales.

Las preguntas concretas están en el informe de decisiones (§G) y la dependencia de cada una en la especificación
(§10.1).

---

## 9. Limitaciones y riesgos conocidos

Sin corregir; no se tocó código.

* Las validaciones de cliente no admiten empresas: nombre y apellido solo aceptan letras y espacios
  (`StoreClientRequest.php:26-39`).
* El email de cliente es único solo por validación, no en la base. La baja de cliente no verifica si está en uso.
* Seis generadores de códigos usan `max(id)+1` sin bloqueo. Probable colisión en altas concurrentes (no
  reproducido).
* `budgets` y `budget_items` usan `decimal(10,2)`: tope de 99.999.999,99 Gs.
* Si se edita una factura en borrador a nombre de otro, los recordatorios de vencimiento igual van al dueño
  (`NotificationReminderService`).
* `receptions.approved_by` no se asigna nunca.
* El disco `local` tiene `'serve' => true`: hay rutas `storage/{path}` con firma.

---

## 10. Punto exacto y siguiente paso

**Dónde quedó la sesión (2026-10-27):** las decisiones A–G y P1–P9 están aprobadas y la especificación versión 2 está
en `docs/especificacion-aseguradoras.html`, con sus diagramas revisados en una vista previa temporal. La página
publicada todavía tiene la versión anterior. No se implementó código del módulo.

**Siguiente paso recomendado, en orden:**

1. ~~**Conservar la revisión de la especificación.**~~ Publicada el 2026-10-27 (sección 6).
2. ~~**Aprobar o ajustar** las decisiones A–G y P1–P9.~~ Aprobadas el 2026-10-27 (sección 5.2).
3. ~~**Actualizar la especificación** con las correcciones aprobadas.~~ Hecho: `docs/especificacion-aseguradoras.html`.
4. ~~**E0:** decidir sobre `composer.lock`.~~ Resuelto el 2026-10-27 (sección 3.1).
5. **Publicar la versión 2** en la página de la especificación (sección 6), **solo con autorización**, y comprobar
   ahí los siete diagramas.
6. **En paralelo:** enviar las preguntas G1–G10 y V1–V14 al contador, a un asesor legal y a talleres que trabajen con
   aseguradoras (sección 8).
7. Después, según el plan de la especificación (§9 y §11 de `docs/especificacion-aseguradoras.html`):
   * correcciones independientes E1–E5 (sugerido: E2 → E3 → E4 → E5 → E1), cada una en su rama y con revisión;
   * spike T0 en una rama descartable;
   * módulo desde M1 (tipo de cliente), coordinado con el frontend.

---

## 11. Cómo retomar en la otra notebook

1. **Este documento ya está en `main`** (sección 3.2). No hay que hacer nada para conservarlo.
2. **Preparar el otro equipo:**
   * clonar los dos repositorios en `C:\laragon\www\` (o hacer `git pull` en `main` si ya existen);
   * `composer install`, `.env` y base de datos según `README.md` (secciones "Requisitos", "Instalación local" y
     "Base de datos");
   * Laragon con **Nginx**;
   * no usar `config:cache` en desarrollo.
3. **Verificar el entorno:** `php artisan test` (o `php -d extension=pdo_sqlite -d extension=sqlite3
   vendor/bin/phpunit` si falta `pdo_sqlite`) y anotar el resultado. La última ejecución verificada es la de la
   sección 1 (374 tests en verde, 2026-10-27); repetirla en el equipo donde se retome.
4. **Abrir las páginas de la sección 6** con la cuenta de claude.ai del usuario.
5. **Abrir Claude Code** en `synctaller-backend` y pedirle que lea `CLAUDE.md`, `docs/handoff.md`, este documento y
   `docs/especificacion-aseguradoras.html` (en especial §10), verifique el estado de Git y retome desde la sección 10.
   La versión 2 ya incorpora la revisión: no hace falta compartirle las páginas publicadas.

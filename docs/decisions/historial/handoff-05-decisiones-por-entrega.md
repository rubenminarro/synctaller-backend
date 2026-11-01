<!-- Decisiones por entrega (secciones 2.54 en adelante). Líneas 1431–1945 de docs/handoff.md hasta el commit 9cae759. Mapa de secciones: docs/handoff.md. -->

# 2.54 Recordatorios de factura al destinatario correcto (2026-10-28)

Entrega E2 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §9). Los recordatorios de
vencimiento iban siempre a `invoice.client`, el dueño de la recepción, aunque en el borrador se hubiera cambiado el RUC o
CI para facturar a otro (por ejemplo, a una aseguradora): el dueño recibía el aviso de una deuda que no era suya.

| Tema | Decisión |
|---|---|
| Regla | Si el `customer_tax_id` de la factura no coincide con el `document_number` del cliente, el recordatorio (`invoice_due_soon` / `invoice_overdue`) se registra `skipped` con el motivo "La factura está a nombre de otro destinatario." Es el mecanismo existente: no se envía email ni se crea otro canal, y la clave de deduplicación evita repetirlo |
| Comparación | Mayúsculas y sin espacios, sin el dígito verificador final (`-N`): CI `1234567` y RUC `1234567-8` cuentan como la misma persona (decisión del usuario del 2026-10-28). Sin documento del cliente no hay con qué comparar y el aviso sale como antes |
| Reenvío | `POST client-notifications/{id}/resend` sigue permitido sobre el aviso omitido y va al cliente (decisión del usuario del 2026-10-28): es una acción manual registrada. Sin `error_code` nuevo |
| Código | `NotificationReminderService::invoiceReminders` (`isAddressedToClient`) y un parámetro opcional `$skipReason` en `ClientNotificationService::notify` |
| Sin cambiar | Contrato de la API, permisos, migraciones, los demás avisos y los recordatorios de facturas a nombre del dueño |

---

# 2.55 Códigos internos con bloqueo (2026-10-28)

Entrega E3 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §9; política P4, §6.4).
Seis altas calculaban el código como `withTrashed()->max('id') + 1` sin bloqueo (`BUD-`, `OT-`, `COM-`, `REP-`, `SRV-`,
`DIA-`): dos altas simultáneas de la misma serie obtenían el mismo código y la segunda fallaba con 500 por el índice
único de `code`.

| Tema | Decisión |
|---|---|
| Contador | Tabla `document_sequences` (migración `000056`: `key` único, `next_value`). `DocumentSequenceService::create(DocumentSeries, closure)` bloquea la fila de la serie con `lockForUpdate` dentro de la transacción del alta, arma el código (prefijo + 6 dígitos, formato sin cambios) y lo pasa al closure que crea el documento. Si el alta falla, el rollback revierte el incremento. Solo se serializan las altas de la misma serie |
| Valor inicial | En el **primer uso** (decisión del usuario del 2026-10-28, en lugar de cargarlo en la migración): el servicio inserta la fila con `insertOrIgnore` y el máximo id + 1 de la tabla, incluidos los eliminados, que es el mismo código que daba el cálculo anterior. La migración es solo estructura. Las series futuras (`ASE-`, `SIN-`) solo se agregan al enum `DocumentSeries` |
| Códigos usados | Con la fila bloqueada, si el código ya existe (también eliminado) se salta al siguiente. Cubre códigos cargados sin pasar por la serie (por ejemplo, la migración `000055` con `DIA-`) |
| Duplicado | Si igual se viola la unicidad del código, la API responde `409 DOCUMENT_CODE_CONFLICT` (`AppException`), nunca 500 (decisión del usuario del 2026-10-28: solo para los códigos, sin mapeo global). Otras violaciones de unicidad no cambian |
| Orden de bloqueo | Documento → serie: la serie siempre al final, y cada serie la usa un solo servicio (sin ciclos). La OT retiene la fila de su serie mientras reserva repuestos en la misma transacción |
| Huecos | Se aceptan (P4): no son documentos fiscales. Facturas, `REC-`, `ENT-` y `PAG-` no cambian (ya usaban `lockForUpdate`) |
| Pruebas | `tests/Feature/Documents/DocumentSequenceTest` (formato, continuidad, salto, rollback, series independientes, 409). La concurrencia real solo se reproduce en MySQL: `tests/Manual/document_sequence_concurrency.php` (N procesos piden códigos a la vez; verifica que no haya repetidos ni errores y restaura el contador) |
| Sin cambiar | Rutas, Resources, permisos y formato de los códigos |

---

# 2.56 Importes del presupuesto en decimal(14,2) (2026-10-28)

Entrega E4 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §9). `budgets.subtotal|tax|total`
y `budget_items.unit_price|total` eran `decimal(10,2)`: tope de 99.999.999,99 Gs. Como los Requests no limitaban precio
ni cantidad y MySQL corre en modo estricto, un presupuesto mayor (por ejemplo, una chapería de 120 millones) fallaba
con 500.

| Tema | Decisión |
|---|---|
| Columnas | Migración `000057`: las cinco columnas pasan a `decimal(14,2)` con `->change()`, conservando default y nulabilidad. Es la misma precisión que las facturas. `quantity` queda en `(10,2)`. El `down()` se detiene con un mensaje si algún importe no entra en `(10,2)`, en lugar de truncar |
| Topes | Decisión del usuario del 2026-10-28: `unit_price` ≤ 999.999.999.999,99 y `quantity` ≤ 99.999.999,99 en los Requests (422 `VALIDATION_ERROR`). Si el total del ítem o el del presupuesto con IVA no entra en `(14,2)`: `BUDGET_AMOUNT_OUT_OF_RANGE` (422, `BudgetService::ensureAmountInRange`). Ningún importe llega a la base fuera de rango |
| Transacción | `BudgetItemController::store\|update` creaba o editaba el ítem y recalculaba sin transacción: si el recálculo fallaba, el ítem quedaba guardado. Ahora las dos operaciones van en `DB::transaction`. La lógica de ítems sigue en el controller (no se movió al Service: fuera de alcance) |
| Alcance | Solo presupuestos (decisión del usuario del 2026-10-28). **Observación sin cambios:** `parts.cost_price\|sale_price`, `part_supplier.last_cost` y `mechanics.hour_cost` siguen en `(10,2)`, con un máximo de 99,9 millones por unidad; `work_order_closings` está en `(12,2)` y alcanza |
| Pruebas | `tests/Feature/Budgets/BudgetAmountRangeTest`: presupuesto de 150.000.000, topes, total del ítem y del presupuesto fuera de rango (sin guardar nada) y edición fuera de rango. SQLite no aplica la precisión de los decimales: la ampliación se verificó en la base MySQL de desarrollo el 2026-10-28. Las cinco columnas quedaron en `decimal(14,2)` con sus defaults, y un presupuesto de 150.000.000 se guardó con total 165.000.000,00 (dentro de una transacción revertida). El `down()` se detuvo con importes grandes, y sin ellos volvió a `(10,2)` y se reaplicó |

---

# 2.57 Registro de la respuesta del cliente al presupuesto (2026-10-28)

Entrega E5 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.4 y §9). `approve` y
`reject` solo cambiaban el estado: no quedaba quién lo registró, por qué medio respondió el cliente ni por qué importe.
`reopen` borraba `approved_at`, así que se perdía el historial, y `Budget` no estaba auditado. Es la base del control
de aceptación del dueño en siniestros (decisión B, M7).

| Tema | Decisión |
|---|---|
| Tabla | `budget_client_acceptances` (migración `000058`): `budget_id`, `kind` (`ClientAcceptanceKind`: `approval\|rejection`), `decided_by` (usuario que lo registra), `decided_at`, `channel` (`ClientDecisionChannel`, nulo), `contact` (nulo), `notes` (nulo) y `owner_amount` (`decimal(14,2)`). Claves foráneas `restrictOnDelete`. Las columnas de aseguradora (`insurer_authorization_id`, `deductible_amount`) y el tipo `re_acceptance` llegan con M7 |
| Importe | `owner_amount` = subtotal sin IVA al responder (decisión del usuario del 2026-10-28: desde E5). En M7, con aseguradora, pasa a ser la parte del dueño |
| Body | Opcional (decisión del usuario del 2026-10-28): `channel`, `contact` (≤ 150) y `notes` (3 a 500), con las reglas de la respuesta a un extra (`DecideBudgetRequest`). Sin body el contrato es el de siempre y la fila se crea igual; el motivo del rechazo también es opcional |
| Inmutable | `BudgetClientAcceptance` no se edita ni se elimina (`BUDGET_CLIENT_ACCEPTANCE_IMMUTABLE`, 409). Reabrir no toca las filas: una respuesta nueva es otra fila |
| Histórico | Sin filas para los presupuestos anteriores (decisión del usuario del 2026-10-28): no hay de dónde sacar quién lo registró ni por qué medio. Conservan `approved_at` |
| Contrato | El detalle del presupuesto (`show` y las respuestas de las transiciones) agrega `client_acceptances`, en orden, con `kind`, `decided_by` (`id`, `name`), `channel` y `owner_amount` (aditivo) |
| Auditoría | `Budget` y `BudgetClientAcceptance` usan `Auditable`. Como cada ítem recalcula los totales del presupuesto, esos cambios también quedan registrados (son precios) |
| Seeders | `BudgetItemSeeder` aprueba (por WhatsApp) y rechaza (por teléfono, con motivo) con el recepcionista del escenario. `DatabaseSeederTest` verifica una aprobación por presupuesto aprobado y los rechazos, incluido el del reabierto |
| Pruebas | `tests/Feature/Budgets/BudgetClientAcceptanceTest`: sin body, con datos, canal y notas inválidos, historial al reabrir (importe de cada respuesta), inmutabilidad y auditoría. Los tests de presupuestos existentes no cambiaron |

---

# 2.58 Spike T0 de tenancy (2026-10-28/29, descartado)

Prueba técnica de `spatie/laravel-multitenancy` **4.2.1** (P2) en una rama descartable. No se mergeó código ni se
aprobó la dependencia. El informe completo, con los criterios S1–S13, las mediciones y lo que se armó para retomarlo
en T2, está en `docs/spike-t0/informe.md`.

| Tema | Resultado |
|---|---|
| Críticos (S1, S2, S4, S5, S7) | Cumplen. Los 393 tests en verde con el paquete y un taller activo; aislamiento de base, tokens, `exists`, bindings, SQL crudo, permisos y cache; un worker alternando talleres sin cruces |
| No cumplen | S8 (un job con la base del taller caída consume su único intento) y S10 (la falla de un taller corta las migraciones de los demás). Los dos se resuelven con código propio ya previsto (§3.5, §3.8) |
| Rendimiento (S13) | Con OPcache, unos 30–45 ms más por request, por encima de los umbrales de G. Hay mitigaciones para probar en T2 |
| Hallazgos de diseño | El paquete identifica el taller al arrancar, no en un middleware (se usó un middleware propio con errores JSON). La cache y `failed_jobs` tienen que ir en la base central. Spatie Permission necesita una tarea propia para olvidar los permisos al cambiar de taller |
| Entorno local | OPcache quedó activado para `php-cgi` (copia del `php.ini` original junto al archivo). Las bases del spike se eliminaron |

---

# 2.59 Cliente persona o empresa (2026-10-29)

Entrega M1 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.1 y §9; decisión P3). El
cliente era siempre una persona: nombre y apellido obligatorios y solo letras, así que una aseguradora o una flota no
se podían cargar. El nombre se armaba a mano en 15 lugares: los 7 que preveía la especificación y 8 Resources más.

| Tema | Decisión |
|---|---|
| Esquema | Migración `000059`: `clients.kind` (`person` por defecto, así los existentes quedan como personas), `business_name` (150) y `address` (255) nulos, e índice en `business_name`. `first_name`/`last_name` pasan a nulos |
| Validación | `StoreClientRequest`: sin `kind`, persona como siempre. Empresa: `business_name` obligatorio y nombre y apellido `prohibited`; persona, al revés. `UpdateClientRequest`: el tipo efectivo es el enviado o el actual. Al cambiar de tipo, los campos del nuevo son obligatorios y `ClientService` vacía los del anterior |
| Cambio de tipo | Bloqueado con recepciones o facturas: `CLIENT_KIND_NOT_EDITABLE` (422, `ClientException`). Ya muestran el nombre del cliente |
| Nombre | `Client::display_name` reemplazó las 15 concatenaciones (factura, PDF, reportes, mantenimiento, historial y los Resources de recepción, vehículo, entrega, diagnóstico y avisos), sin cambiar las claves. Para personas el texto es idéntico: los tests existentes de esas áreas pasaron sin tocarlos |
| Saludo | `greeting_name`: el nombre de pila de una persona, como antes ("Hola, Ana"; decisión del usuario del 2026-10-29), y la razón social de una empresa |
| Búsquedas | `search` en clientes, vehículos, presupuestos, OT, recepciones y diagnósticos (solo el bloque del cliente; el del mecánico no cambia) y los filtros `first_name` (clientes) y `client_first_name` (vehículos) también buscan por razón social (decisión del usuario del 2026-10-29) |
| Baja (D9) | Pasó de `ClientController` a `ClientService::delete`. Con vehículos vigentes, recepciones (incluidas las eliminadas) o facturas responde `409 CLIENT_IN_USE` (decisión del usuario del 2026-10-29). **Cambio de comportamiento:** antes esa baja funcionaba |
| Seeder | `ClientsSeeder` agrega una empresa (`ClientsSeeder::COMPANY`, "Transportes del Este S.A.") sin vehículos ni recepciones. `DatabaseSeederTest` verifica los dos tipos |
| Pruebas | `tests/Feature/Clients/ClientKindTest` (7): alta de persona como antes, alta de empresa, validaciones por tipo, cambio de tipo con y sin recepciones, baja en uso, empresa mostrada y encontrada por razón social, y saludo |
| Fuera de alcance | El dígito verificador del RUC (V10), la baja del cliente de una aseguradora (M2) y el email único en la base (D7, decisión del usuario del 2026-10-29: queda para después) |
| Frontend | **No crear empresas en producción hasta adaptar `../synctaller-frontend`**: `first_name`/`last_name` como `string \| null`, `clientName()` con `display_name`, y el selector persona/empresa en el formulario. Para personas el contrato es el de antes, con campos agregados |

---

# 2.60 Aseguradoras (2026-10-29)

Entrega M2 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.2 y §9; decisión D). Reglas
vigentes en `CLAUDE.md`, 6.22.

| Tema | Decisión |
|---|---|
| Modelo | Migración `000060`: `insurers` (`client_id`, `code` `ASE-`, `status`, `payment_term_days`, `notes`, `created_by`, baja lógica) e `insurer_contacts` (`name`, `role`, `phone`, `email`, `is_primary`, `notes`). Los datos fiscales y de contacto de la aseguradora son los de su cliente empresa: no se duplican |
| Unicidad | Una aseguradora vigente por cliente, controlada en `InsurerService` y no con un índice único, para que una aseguradora dada de baja no retenga a su cliente (se puede volver a vincular) |
| Avisos (D) | Decisión del usuario del 2026-10-29: solo el cliente creado junto con la aseguradora nace con `notifications_enabled = false`. Un cliente existente conserva su configuración y la respuesta lo advierte en `meta.warnings`. Motivo: `notifications_enabled` es un interruptor único para los 6 avisos al cliente; desactivarlo a una empresa con vehículos propios le cortaría también esos avisos. Verificado en el código (`ClientNotificationService`): no afecta a los avisos internos |
| Alta | `client_id` o `client`, uno de los dos (`required_without` y `prohibits`). Los datos de `client` se validan con las reglas de `StoreClientRequest` con `kind = company`, sin duplicarlas; sus errores vuelven como `client.<campo>` |
| Código | `ASE-` es una serie nueva de `DocumentSequenceService` (E3): un caso más en `DocumentSeries`, sin migración propia |
| Fuera de alcance | `deductible_billing_mode` (decisión del usuario del 2026-10-29: se agrega en M10, en `insurers` y `claims`) y el bloqueo de la baja con siniestros (`INSURER_IN_USE`, M3) |
| Permisos | Los de la especificación (decisión del usuario del 2026-10-29): `insurers.index\|show` para recepcionista, jefe de taller y cajero; `insurers.store\|update\|destroy\|change-status` para recepcionista. Sin permisos propios para contactos |
| Cliente | `ClientService` bloquea la baja del cliente vinculado (`CLIENT_LINKED_TO_INSURER`, 409) y su cambio a persona (`CLIENT_KIND_NOT_EDITABLE`) |
| Seeder | `InsurerSeeder` (después de `ClientsSeeder`), por `InsurerService` con el recepcionista: "Seguros del Sur S.A." activa, con dos contactos, y "Aseguradora Guaraní S.A." inactiva. `DatabaseSeederTest` verifica los dos estados, el cliente empresa sin avisos y el contacto principal |
| Pruebas | `tests/Feature/Insurers/InsurerTest` (7): alta con cliente nuevo y existente (advertencia), validaciones, estados, contactos con un solo principal y binding anidado, protección del cliente y nuevo vínculo tras la baja, y permisos por rol |

---

# 2.61 Siniestros (2026-10-29)

Entrega M3 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.3, §5.2 y §9). Reglas
vigentes en `CLAUDE.md`, 6.23.

| Tema | Decisión |
|---|---|
| Modelo | Migración `000061`: `claims` con los campos de §4.3 (número y póliza, tipo, fecha y descripción, asegurado, perito, deducible, notas de cobertura, estado y datos de cierre y cancelación). `SIN-` es una serie más de `DocumentSequenceService` |
| Unicidad | Un siniestro vigente (no cancelado) por recepción, controlado en `ClaimService` (decisión del usuario del 2026-10-29): el cancelado queda en el historial y se puede abrir otro, por ejemplo con otra aseguradora. `Reception::currentClaim()` es el vigente |
| Asegurado | En uno propio, sin datos del asegurado se copian los del cliente de la recepción, con `insured_client_id` (decisión del usuario del 2026-10-29). En uno de tercero el nombre es obligatorio |
| Sincronización | `ClaimStatusService`, sin dependencias para evitar un ciclo (`ClaimService` usa `ReceptionStatusService`): `VehicleDeliveryService::deliver` lo cierra, `cancel` lo reabre y `ReceptionStatusService::cancel` lo cancela con el motivo de la recepción. Todo en la transacción del evento. Orden de bloqueo: recepción → siniestro |
| Edición | Solo `open` y con la recepción editable. Recepción, aseguradora y tipo son `prohibited`. El deducible se edita libremente hasta M9 (`CLAIM_DEDUCTIBLE_LOCKED` con facturas). La cancelación manual reutiliza `CancelReceptionRequest` (motivo obligatorio) |
| Aseguradora | La baja con siniestros, incluso cancelados, responde `INSURER_IN_USE` (409): completa lo que M2 dejó pendiente |
| Visibilidad | El detalle de la recepción agrega `claim` solo si el usuario tiene `claims.show` (decisión del usuario del 2026-10-29): el mecánico tiene `receptions.show`, pero no debe ver pólizas ni datos del asegurado (§8 de la especificación) |
| Permisos | `claims.index\|show` para recepcionista, jefe de taller y cajero; `claims.store\|update\|cancel` para recepcionista y jefe de taller. Mecánico y depósito sin acceso |
| Seeder | `ClaimSeeder` (después de `ReceptionSeeder`), con la aseguradora activa de `InsurerSeeder` y el recepcionista del escenario. Los tres nacen abiertos; el estado final sale de la sincronización: C07 abierto, H07 cerrado (se entrega) y X01 cancelado (se cancela la recepción) |
| Pruebas | `tests/Feature/Claims/ClaimTest` (8): asegurado copiado y visibilidad en la recepción, tercero, unicidades y nuevo siniestro tras cancelar, aseguradora inactiva y recepción cerrada, edición y cancelación, sincronización con entrega, anulación y cancelación de la recepción, aseguradora en uso y permisos |

---

# 2.62 Documentos privados de siniestros (2026-10-29)

Entrega M4 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.3, §8 y §9; decisión E).
Reglas vigentes en `CLAUDE.md`, 6.24.

| Tema | Decisión |
|---|---|
| Disco | `claim_documents` en `config/filesystems.php`: privado, `serve => false`, `throw => true`. `php artisan route:list --path=storage` sigue mostrando solo las 2 rutas del disco `local` (deuda #14): el disco nuevo no agrega ninguna |
| Tabla | Migración `000062`: `claim_documents` (`type`, `original_name`, `path` único, `mime`, `size`, `sha256`, `notes`, `uploaded_by`, `deleted_by`, `deletion_reason`, baja lógica). `insurer_authorization_id` se agrega en M6 (decisión del usuario del 2026-10-29) |
| Validación | `UploadClaimDocumentRequest`: `extensions` (nombre), `mimetypes` (contenido, por `finfo`) y una regla `after` que exige que la extensión coincida con el contenido (un PNG llamado `.pdf` se rechaza). 10 MB. Hallazgo: `UploadedFile::fake()` informa el tipo según la extensión del nombre, así que con fakes la validación por contenido pasa siempre; los tests usan archivos reales |
| Estado | Se suben documentos con el siniestro abierto o cerrado (decisión del usuario del 2026-10-29: el informe final del perito puede llegar después de la entrega); cancelado → `CLAIM_NOT_EDITABLE` |
| Descarga | `FileResponse::storedFileResponse` (nuevo): adjunto, tipo guardado y `nosniff`; el `fileResponse` de los PDF y CSV no cambió. Nombre saneado (sin comillas, barras ni caracteres de control). Evento de auditoría nuevo `AuditEvent::DOWNLOADED` (`downloaded`, "Descargado") con el siniestro, el tipo y el nombre. Archivo faltante en el disco → `CLAIM_DOCUMENT_FILE_MISSING` (404), sin registrar la descarga |
| Baja | Solo administrador (`claim-documents.destroy`), con motivo (`DestroyClaimDocumentRequest`). Lógica: el archivo queda en el disco hasta definir la retención (G10) |
| Auditoría | `ClaimDocument` es `Auditable` con `$auditExclude = ['path']`: la ruta interna no queda en `audit_logs` |
| Seeder | `ClaimDocumentSeeder` (después de `ClaimSeeder`): denuncia en PDF y foto PNG del siniestro abierto (C07), por el Service con el recepcionista. `DatabaseSeederTest` usa `Storage::fake('claim_documents')` y verifica los archivos |
| Pruebas | `tests/Feature/Claims/ClaimDocumentTest` (6): almacenamiento privado con UUID y sha256 (sin `path` en la API ni en la auditoría), rechazo de SVG, HTML, extensión que no coincide y más de 10 MB, descarga auditada como adjunto, baja (403 para el recepcionista, 404 después y archivo conservado), aislamiento por siniestro y estados, y permisos. Los helpers de los tests de siniestros pasaron a `ClaimTestCase` |

---

# 2.63 Responsable por línea del presupuesto (2026-10-29)

Entrega M5 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.4, §5.2 y §9). Reglas
vigentes en `CLAUDE.md`, 6.4.

| Tema | Decisión |
|---|---|
| Columna | Migración `000063`: `budget_items.responsibility` (`string(20)`, `owner` por defecto). Las líneas existentes quedaron a cargo del dueño sin migrar datos (en la base local, las 73) |
| Por defecto | `owner` aunque la recepción tenga siniestro (decisión del usuario del 2026-10-29): lo que paga la aseguradora se marca a propósito, y el frontend actual, que no manda el campo, no cambia nada |
| Regla | `insurer` exige un siniestro abierto en la recepción del presupuesto (`BUDGET_ITEM_INSURER_WITHOUT_CLAIM`, 422). Lo valida `BudgetService::ensureResponsibilityAllowed`, desde `BudgetItemController` (alta y edición) y desde el seeder. Solo en borrador, como el resto del ítem |
| Siniestro cancelado | `ClaimService::cancel` devuelve a `owner` las líneas `insurer` de los presupuestos **en borrador** de la recepción, uno por uno (queda en la auditoría de `BudgetItem`). Los enviados y aprobados no se tocan (decisión del usuario del 2026-10-29). La cancelación de la recepción ya cancela sus borradores |
| API | `BudgetItemResource` agrega `responsibility` (`value`/`label`). Aditivo |
| Seeder | `BudgetItemSeeder` carga a la aseguradora la línea del presupuesto del siniestro abierto (C07). `DatabaseSeederTest` verifica los dos valores |
| Pruebas | `tests/Feature/Budgets/BudgetItemResponsibilityTest` (4): dueño por defecto aun con siniestro, aseguradora solo con siniestro abierto (y valor inválido), solo en borrador, y vuelta al dueño al cancelar el siniestro (el enviado no cambia) |

# 2.64 Autorizaciones de la aseguradora (2026-10-29)

Entrega M6 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.5, P7 y decisión C).
Reglas vigentes en `CLAUDE.md`, 6.25.

| Tema | Decisión |
|---|---|
| Tablas | Migración `000064`: `insurer_authorizations` (única por `budget_id` + `version`), `insurer_authorization_items` y `claim_documents.insurer_authorization_id` (nulable, `restrictOnDelete`) |
| Unidad (P7) | Se guarda sin IVA, como el presupuesto. `amounts_include_tax` es obligatorio en cada registro (decisión del usuario del 2026-10-29): mientras V2 no tenga respuesta, quien carga dice en qué unidad vino el importe. Con IVA se convierte con la tasa del presupuesto (`invoice.default_tax_rate`) |
| Cobertura | Una línea por cada ítem `insurer`, ni más ni menos. Tope por importe y por cantidad (`INSURER_AUTHORIZATION_EXCEEDS_BUDGET`): lo que exceda va en un complementario (V1 sigue abierta) |
| Versiones | La nueva sustituye a la vigente. Si sube el importe de alguna línea, exige un documento del siniestro (decisión del usuario del 2026-10-29: evidencia obligatoria al subir); el documento queda vinculado y no se da de baja (`CLAIM_DOCUMENT_IN_USE`, 409) |
| Copia del ítem | Durante la implementación: `BudgetItemController::destroy` borra el ítem físicamente, y con `restrictOnDelete` borrar un ítem de un presupuesto reabierto habría dado 500. La línea guarda descripción, cantidad y total presupuestados, y la clave foránea pasa a `null` |
| Invalidación | `BudgetService::reopen` y `cancel` (ahora en una transacción) y `ReceptionStatusService::cancel` (por cada presupuesto que cancela). Cancelar el siniestro no la toca, igual que M5 con los presupuestos enviados |
| Sin dependencias | `InsurerAuthorizationService` no depende de otros Services: lo usan `BudgetService` y `ReceptionStatusService` |
| Detalle del presupuesto | `insurer_authorization` (vigente, resumida) solo con `insurer-authorizations.show`, como `claim` en la recepción. Aditivo |
| Seeder | C05 suma un siniestro abierto y sus líneas a la aseguradora: versión 1 parcial y versión 2 (sube la instalación, con el documento `authorization` como evidencia). C07: autorización total, invalidada al reabrir. `DatabaseSeederTest` verifica `current`, `superseded` e `invalidated` |
| Pruebas | `tests/Feature/Claims/InsurerAuthorizationTest` (7): registro por línea con conversión de IVA, coincidencia y topes, precondiciones, versiones y evidencia, invalidación (y borrado del ítem después de reabrir), inmutabilidad y permisos |

---

# 2.65 Bloqueo de la OT y excepciones internas (2026-10-29)

Entrega M7 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.4, §5.4, §5.5 y §9;
decisiones B, C y P5). Reglas vigentes en `CLAUDE.md`, 6.5 y 6.26.

| Tema | Decisión |
|---|---|
| Esquema | Migración `000065`: `budget_client_acceptances.insurer_authorization_id` (nulo, `restrictOnDelete`) y `deductible_amount` (`decimal(14,2)`, nulo, tal como se informa). Migración `000066`: `internal_exceptions` (`type`, `subject` morph, `amount`, `reason`, `granted_by`, `granted_at`, `consumed_at`, `consumed_by` morph). Alias `work_order` en el morph map (WorkOrder no se usaba en ninguna relación polimórfica) |
| Autorización exigida | Decisión del usuario del 2026-10-29 (corrige §5.5, que pedía cada ítem `authorized\|partial`): basta una autorización vigente del siniestro abierto. M6 ya obliga a decidir todas las líneas; lo no cubierto pasa al dueño y lo controla la aceptación |
| Sin autorización | Decisión del usuario del 2026-10-29: mientras no hay autorización, las líneas `insurer` no se suman a la parte del dueño. Si se sumaran, la aceptación quedaría siempre desactualizada y `start_without_authorization` no serviría |
| Siniestro cancelado | Las líneas `insurer` de un presupuesto enviado o aprobado no cambian (M5), pero sin siniestro abierto son del dueño: la OT no exige autorización y sí la re-aceptación por el total. Así una cancelación no deja pasar una OT con la aceptación vieja |
| Excepción al crear la OT | Decisión del usuario del 2026-10-29: `internal_exception_id` opcional en `POST work-orders`; se valida (tipo, presupuesto, sin usar) y se consume en la transacción del alta. Pasarla cuando no hace falta también es un error (`INTERNAL_EXCEPTION_NOT_APPLICABLE`): una excepción habilita una operación concreta |
| Absorción | Decisión del usuario del 2026-10-29: M7 solo la otorga (sobre la OT, con importe); el reparto la consume en M8 (R6) |
| Una sin usar | Una excepción sin usar por sujeto y tipo (`INTERNAL_EXCEPTION_ALREADY_GRANTED`, 409). Decisión de implementación para que M8 no tenga que elegir entre varias; se puede revisar en M8 |
| Body de la excepción | `type` + `subject_id`: el tipo define si el sujeto es un presupuesto o una OT (la tabla guarda el morph) |
| Re-aceptación | Decisión del usuario del 2026-10-29: con el presupuesto aprobado y la OT sin cerrar, sin exigir que el importe haya subido. `channel` obligatorio (decisión de implementación: es la evidencia de B; en `approve` sigue opcional por compatibilidad). Con la OT, el importe sale de la ejecución actual sin los extras, porque el tope suma los extras aprobados aparte (§5.4) |
| Ejecución actual | `OwnerShareService::workOrderOwnerAmount`: como el cierre (mano de obra y servicio por lo presupuestado, repuesto de catálogo por lo usado), pero las líneas sin resolver por lo previsto (cantidad × precio): una OT recién creada no muestra una parte menor que la aceptada. El refactor compartido con la factura (`WorkOrderSaleBreakdown`) sigue en M8 |
| Advertencia de B | Nombre `owner_amount_warning` (decisión del usuario del 2026-10-29; tarea interna de §10.3), solo con `claims.show`. Incluye el deducible que subió desde la aceptación, que en M8 bloquea el reparto (`CLAIM_DEDUCTIBLE_NOT_ACCEPTED`) |
| Advertencia de P5 | Decisión del usuario del 2026-10-29: `has_claim` en el detalle de la OT (sin permiso: el depósito agrega extras y no tiene `claims.show`) y el texto de la especificación en `meta.warnings` del alta de un extra |
| Permisos | Los de §8 (confirmados el 2026-10-29): `internal-exceptions.grant` para el jefe de taller; `index\|show` para jefe de taller y cajero. La re-aceptación usa `budgets.approve` y el historial `budgets.show` |
| Bloqueo | Presupuesto → autorización → excepción en el alta de la OT (el presupuesto solo se bloquea si tiene líneas `insurer`: el particular no cambia); OT → excepción en la absorción |
| Particular | Sin líneas `insurer` no cambia nada: mismo `owner_amount` (subtotal), mismas validaciones y errores. Claves nuevas en el detalle de la OT (`has_claim`, `owner_amount_warning` en `null`) y en las aceptaciones; contrato aditivo |
| Seeder | C05 pasa de enviado a aprobado: el jefe de taller otorga la excepción antes de la versión 1 (queda sin usar), el dueño aprueba con la versión 1 (paga la mitad de la instalación) y re-acepta con la 2 (no paga nada). `DatabaseSeederTest` ajusta los conteos de presupuestos (25 aprobados, 2 enviados) y verifica la aprobación, la re-aceptación y la excepción. La absorción no se siembra: llega con su uso en M8 |
| Pruebas | `tests/Feature/Claims/WorkOrderInsurerFlowTest` (8): autorización exigida, Ejemplo 3 (la excepción no reemplaza la aceptación) y re-aceptación, excepción otorgada y consumida (inmutable y auditada), siniestro cancelado, advertencias de B (Ejemplo 2 y deducible) y P5, absorción, particular sin cambios y permisos. Los tests existentes de OT, presupuestos y repuestos no cambiaron |
| Fuera de alcance | Reparto, R5 y consumo de la absorción (M8); el bloqueo de entrega y facturación por deducible (M9). Una excepción `start_without_authorization` sin usar sigue valiendo si el presupuesto se reabre y se vuelve a aprobar: si molesta, se decide en M8 |

---

# 2.66 Decisión P10: notas de crédito emitidas fuera del sistema (2026-10-29)

Decisión de diseño, **sin código todavía**. La primera respuesta a G5 (2026-10-28) indica que una factura emitida y
cuestionada se corrige con una nota de crédito, no anulándola, y que mientras SyncTaller no las emita se acepta otro
procedimiento autorizado. El usuario cerró P10 antes de diseñar M8, sin esperar V18–V19. Texto aprobado en
`docs/especificacion-aseguradoras.html` (§5.7 y §10.2).

| Tema | Decisión del usuario (2026-10-29) |
|---|---|
| Mecanismo | Se registra en SyncTaller la nota de crédito emitida fuera del sistema (número, fecha, motivo y líneas). Emitirlas desde SyncTaller queda para cuando SIFEN sea obligatorio o se decida |
| Alcance | Cualquier factura emitida, también la del flujo particular: la regla es fiscal. Aditivo: las validaciones y errores actuales no cambian |
| Importe | Por línea y parcial: cada línea de la nota referencia una línea de la factura y no supera lo que esa línea tiene sin acreditar. Libera exactamente la porción del reparto (R8 es por porción) |
| Con pagos | El total de la nota no supera el saldo pendiente. Para acreditar lo cobrado se anulan antes los pagos (procedimiento vigente). Sin saldos a favor ni devoluciones en el MVP |
| Efectos | El saldo y el estado de cobro descuentan lo acreditado. El facturado de una porción es el neto (base facturada menos acreditada): P6 permite sustituir una versión del reparto cuando su facturado neto es 0, y `ALLOCATION_HAS_INVOICES` informa también lo acreditado |
| Anulación | La vigente (6.10) no cambia mientras V19 no diga lo contrario: una emitida sin pagos todavía puede anularse |
| Implementación | Entrega propia, después de M8 y antes de cerrar M9. Tablas, errores, anulación de un registro mal cargado y efectos en la entrega, los recordatorios y los reportes se diseñan al iniciarla |
| Pendiente externo | V18 (procedimiento autorizado y plazo de un año) y V19 (anulación de una factura que la aseguradora todavía no recibió). Aprobar P10 no las resuelve |

---

# 2.67 Cierres internos de validaciones (2026-10-29)

Antes de diseñar M8, el usuario cerró como decisiones de producto las preguntas que no necesitan un profesional. Detalle
en `docs/especificacion-aseguradoras.html`, §10.1 ("Cierres internos").

| Tema | Decisión |
|---|---|
| V21 | Las respuestas a G1–G5 las dio **un dueño de taller que también lleva la contabilidad**: valen como base. Por recomendación (decisión del usuario), igual se busca la respuesta correcta y completa de un contador; su parte fiscal (IVA del deducible, retención del 30 %, nota de crédito y su plazo) sigue sin validar. M10 y M12 siguen exigiendo la confirmación escrita del contador. P10 se mantiene: no supone ninguna regla fiscal, solo registra lo emitido afuera |
| V9 | Muchos talleres a futuro, pocos al principio. Estándar de desarrollo y pruebas de la línea T: 10 talleres (al menos 3 dentro de la suite), con el volumen de los datos de ejemplo por taller. La infraestructura de producción se decide antes de T6 |
| Cerradas | G6, G7, G8, G9, V1, V2, V4, V6, V7, V8 y V12, con las reglas ya aprobadas o el alcance del MVP. Se revisan con el uso real |
| Pendientes con profesional | Contador: G1, V15, V16, V20, G3, V17, G4, V22, V18, V19, V11 y la dirección en la factura (V10). Asesor legal: V5, G10, V14 y la parte legal de V13. El dígito verificador del RUC se toma de la documentación oficial de la SET |
| Efecto | Ninguna pendiente bloquea M8, P10 ni M9 con deducible 0 |

---

# 2.68 Reparto económico (2026-10-29)

Entrega M8 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.5, §5.7, §6.2–6.3 y §9).
Reglas vigentes en `CLAUDE.md`, 6.27.

| Tema | Decisión |
|---|---|
| Refactor | `WorkOrderSaleBreakdown` sale de `InvoiceService::linesFromWorkOrder` (privado) sin cambiar el resultado. Primero el test de igualdad (`tests/Feature/Invoices/WorkOrderSaleBreakdownTest`, contra el código anterior); los tests de facturas, pagos y OT existentes pasaron sin tocarlos. Agrega a cada concepto su ítem del presupuesto y si es extra |
| Tablas | Migración `000067`: `work_order_allocations` (única por OT y versión) y `work_order_allocation_lines` (única por reparto, concepto y tipo). Alias `work_order_allocation` en el morph map (consume la excepción de absorción). En MySQL falló la primera vez: el nombre automático de la clave foránea a `insurer_authorization_items` tenía 66 caracteres (límite 64) y SQLite no lo detecta; se le dio un nombre propio y quedó la regla en `CLAUDE.md` §3 |
| Generación | Decisión del usuario del 2026-10-29: a mano (`POST`), la revisa el cajero. Con una confirmada, el borrador nuevo es su copia |
| Factura en M8 | Decisión del usuario del 2026-10-29: `WORK_ORDER_REQUIRES_ALLOCATION_INVOICING` ya en M8 (la especificación lo dejaba para M9), para que una OT de siniestro no se facture entera al dueño en el medio. Esas OT no se facturan hasta M9; no hay siniestros reales porque el frontend todavía no los usa |
| Deducible | Bajarlo por debajo de lo confirmado se rechaza (`CLAIM_DEDUCTIBLE_BELOW_ALLOCATED`, decisión del usuario del 2026-10-29, nueva). `deductible_fiscal_pending` es calculado (deducible > 0), no una columna (decisión del usuario) |
| Absorción en otra versión | La excepción consumida queda ligada a la versión que la usó: la versión nueva pide otra (decisión del usuario del 2026-10-29) |
| Sin autorización | El borrador se genera con todo al dueño y una advertencia (decisión del usuario del 2026-10-29); R5 exige su aceptación o la autorización |
| Autorización cambiada | Solo advertencia en el resumen (decisión del usuario del 2026-10-29); R3 se valida contra la vigente al confirmar |
| Vista previa | El borrador trae `checks` con cada regla (P6: resumen previo a confirmar) sin confirmar nada |
| Borrador descartado | Se elimina sin rastro y su número de versión se vuelve a usar (no es un documento) |
| Permisos | Los de §8, confirmados: gestiona el cajero; consultan recepcionista, jefe de taller y cajero; el administrador todo |
| Corrección de M7 | La re-aceptación (2.65) se bloqueaba con la OT completada, pero el reparto se arma con la OT completada y la salida (a) del Ejemplo 2 (decisión B) es que el dueño re-acepte. Ahora solo la bloquea la OT cancelada (o la recepción cerrada); con la OT completada toma lo ejecutado. El test de M7 se ajustó |
| Seeder | C05 suma una OT completada (`work_order.day`: corre la línea de tiempo de la OT dos días, para crearla después de la re-aceptación) y `AllocationSeeder` (después de `WorkOrderSeeder`, con el cajero) deja la versión 1 sustituida, la 2 confirmada y la 3 en borrador. `DatabaseSeederTest` ajusta los conteos de recepciones, OT y cierres y verifica las tres versiones |
| Pruebas | `tests/Feature/Claims/WorkOrderAllocationTest` (8): Ejemplo 1 propuesto y confirmado (con el bloqueo de la factura y del siniestro), Ejemplo 2 con absorción insuficiente (R6), absorción parcial y re-aceptación (R5), R1–R4 y R6 al confirmar e inmutabilidad, versiones y scope, deducible (desconocido, no aceptado, R7, no baja de lo asignado y Ejemplo 4 con dos OT, en secuencia), sin autorización, particular y OT sin completar, y permisos |
| Fuera de alcance | Facturas por responsable y `ALLOCATION_HAS_INVOICES` (M9, con el facturado neto de P10); la "venta facturable" en los reportes (§6.2); la concurrencia real del deducible compartido en MySQL (prueba aparte, §6.3) |

---

# 2.69 Notas de crédito emitidas fuera del sistema (2026-10-29)

Implementación de la decisión P10 (2.66). Reglas vigentes en `CLAUDE.md`, 6.28.

| Tema | Decisión |
|---|---|
| Tablas | Migración `000068`: `invoice_credit_notes` y `invoice_credit_note_items`. Probada en una base MySQL temporal (todas las migraciones y el rollback de esta), como pide la regla nueva de `CLAUDE.md` §3 |
| Base de la línea | Proporcional a la línea de la factura (`amount × base_total / total`), no por la tasa: así respeta el descuento de la línea. Si se acredita todo lo que le queda, es exactamente la base restante (sin diferencias de redondeo acumuladas) |
| Saldo | `Invoice::balance()` = total − pagos − notas vigentes. Todo lo que usa el saldo (pagos, entrega, recordatorios, reportes, historial, aviso) quedó correcto sin tocarlo; se agregó la precarga `credited_total` junto a `paid_total` (listado de facturas y recordatorios) y `creditNotes` en los reportes |
| Estado `credited` | Decisión del usuario del 2026-10-29: valor nuevo de `InvoicePaymentStatus` ("Acreditada") para la factura saldada solo con notas, sin pagos. **Cambio de contrato:** el frontend debe reconocerlo |
| Permisos | Decisión del usuario del 2026-10-29: registrar y anular con permisos nuevos `invoice-credit-notes.store` y `invoice-credit-notes.cancel` (cajero); consultar con `invoices.show` (recepcionista y cajero), sin permiso propio |
| Anulación | Un registro mal cargado se anula con motivo (reutiliza `CancelPaymentRequest`), como un pago. La factura con notas vigentes no se anula (`INVOICE_HAS_CREDIT_NOTES`) |
| Errores | En `InvoiceException` (módulo de facturación), sin una clase nueva |
| Número | Texto libre (≤ 30) y timbrado opcional, porque el formato del "otro procedimiento" (V18) no se conoce; único entre las vigentes |
| PDF y reportes | El PDF de la factura lista las notas y el saldo (decisión del usuario). El reporte de ventas agrega `credit_notes` y `net_total` (con sus etiquetas en `ReportExportService::LABELS`); `total` no cambió |
| Avisos | Sin aviso por email al cliente (decisión del usuario) |
| Seeder | `CreditNoteSeeder` (después de `PaymentSeeder`, con el cajero): H13 acreditada entera (estado `credited`) y H07 con una nota parcial y otra anulada. `DatabaseSeederTest` ajusta los estados de cobro y verifica las notas |
| Pruebas | `tests/Feature/Invoices/CreditNoteTest` (6): registro por línea con base proporcional y exacta, línea ajena y scope; tope del saldo, `paid` y `credited` (también en el listado); anulación de la nota y de la factura e inmutabilidad; validaciones y PDF; reporte de ventas y CSV; permisos. Los tests existentes de facturas, pagos, reportes, entregas y avisos pasaron sin cambios |
| Pendiente externo | V18 (procedimiento autorizado y plazo de un año) y V19 (anulación de una factura no recibida) |

---

# 2.70 Facturas por responsable (2026-10-29)

Entrega M9 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §4.6, §5.7, §7.1 y §9;
decisiones A y F). Reglas vigentes en `CLAUDE.md`, 6.29.

| Tema | Decisión |
|---|---|
| Tablas | Migración `000069`: `invoices.allocation_id` e `invoice_items.allocation_line_id`, nulos. Probada en una base MySQL temporal (todas las migraciones y el rollback de esta) |
| Facturado neto | `AllocationBillingService` (sin dependencias): base de las líneas en facturas vigentes menos la acreditada por notas (P10). Lo usan la generación, la emisión (R8), la corrección del reparto (P6) y la entrega |
| Condición | Decisión del usuario del 2026-10-29: aseguradora a crédito con vencimiento hoy + `payment_term_days` (30 si no tiene); dueño al contado. Editables en borrador |
| Líneas | Una por porción (decisión del usuario), aunque un concepto tenga dos porciones del mismo cliente. Concepto entero: su cantidad y precio; una parte: cantidad 1 y la cantidad real en la descripción (Ejemplo 5) |
| Una por cliente y versión | Decisión del usuario del 2026-10-29: lo acreditado se vuelve a facturar desde una versión nueva del reparto, no con otra factura en la misma versión |
| PDF | Decisión del usuario: la factura a la aseguradora trae el número de siniestro y la póliza |
| Entrega | Decisión del usuario: el control del deducible (A) se aplica a toda la recepción con siniestro abierto, también a las OT sin reparto. Las OT con reparto se controlan por porción y por factura; las particulares, como siempre |
| Cambio de comportamiento | Con un siniestro abierto de deducible desconocido (nulo) la recepción ya no se entrega. `ClaimTest` (M3) entregaba con deducible nulo: ahora usa deducible 0 |
| Excepciones | Los errores de entrega salen de `VehicleDeliveryException` (como los demás `DELIVERY_*`); `AllocationBillingService` solo responde si falta algo |
| Contrato (F) | `invoices[]` e `invoices_count` en el detalle de la OT con `invoices.show`; `invoice` queda como clave heredada. El historial (`vehicle-history.view`) agrega `invoices[]` sin permiso propio, como ya mostraba `invoice`, y `summary.by_recipient` |
| Bloqueo | Generar: siniestro → OT → reparto → factura. Emitir bloquea la factura y después el reparto (la generación no bloquea facturas existentes, así que no se cruzan) |
| Seeder | La OT de C05 suma un extra aprobado (a cargo del dueño) y `AllocationInvoiceSeeder` (después de `InvoiceSeeder`, con el cajero) emite las dos facturas: la de la aseguradora a crédito, impaga, y la del dueño al contado, cobrada. Es el escenario obligatorio de §7.1; el opcional (deducible mayor a 0) no se sembró. `DatabaseSeederTest` ajusta los conteos de facturación y verifica las dos facturas |
| Pruebas | `tests/Feature/Claims/AllocationInvoiceTest` (5): Ejemplo 1 facturado a cada responsable (importes de la especificación: 1.980.000 y 1.067.000 con IVA), duplicado y cliente sin porciones, `invoices[]` y su ausencia para el mecánico; entrega por factura e historial por destinatario; deducible en la generación, la emisión y la entrega, y `CLAIM_DEDUCTIBLE_LOCKED`; corrección con `ALLOCATION_HAS_INVOICES` resuelta con una nota de crédito; permisos. Los helpers del Ejemplo 1 pasaron a `ClaimTestCase` |
| Fuera de alcance | M10 (deducible mayor a 0, espera G1), M11 (avisos a aseguradoras) y M12 (retenciones, espera V17) |

# 2.71 Reportes del módulo de aseguradoras (2026-10-30)

Entrega M11 de la especificación de aseguradoras (`docs/especificacion-aseguradoras.html`, §9; decisión D y V7).
Reglas vigentes en `CLAUDE.md`, 6.30.

| Tema | Decisión |
|---|---|
| Alcance | Decisión del usuario del 2026-10-30: M11 son los reportes. Los avisos a la aseguradora (plantillas, contacto de facturación, preferencias) esperan V7; mientras tanto rige D (cliente de la aseguradora sin avisos) |
| Cuentas por cobrar | Decisión del usuario: reporte nuevo `reports/receivables` con permiso `reports.receivables` (cajero), en lugar de ampliar el reporte de clientes. Misma regla de vencimiento que las cuentas por pagar. Agrega antigüedad por días de atraso |
| Tipo de destinatario | Enum `InvoiceRecipientType` (`insurer|customer`). Una aseguradora dada de baja sigue clasificando como aseguradora |
| Resumen del siniestro | Decisión del usuario: por siniestro (`claims/{claim}/summary`) y además un reporte (`reports/claims`, permiso `reports.claims`, cajero; período por fecha del siniestro) |
| Visibilidad | Decisión del usuario: por bloque según permiso. Presupuestado, autorizado y aceptado con `claims.show`; el reparto con `work-order-allocations.show`; facturado y cobrado con `invoices.show` (el jefe de taller no ve facturas, como hoy). Las alertas siguen la misma visibilidad |
| Unidades | Presupuesto, autorización, aceptación y reparto sin IVA; facturación con IVA. Van en bloques separados y no se suman entre sí |
| Totales | Presupuestos: solo los aprobados (los que pasan a OT); se listan todos los no cancelados. Facturas: solo las emitidas; los borradores se cuentan aparte |
| Contrato | Aditivo: `recipient_type` y `recipient_label` en `reports/clients` (`receivables.clients`). Tres rutas nuevas; sin migración |
| Verificación en MySQL | Resúmenes, cuentas por cobrar, reporte de siniestros y CSV corridos de solo lectura sobre la base local sembrada (4 siniestros) |
| Pruebas | `tests/Feature/Claims/InsurerReportsTest` (4): cuentas por cobrar por destinatario con antigüedad, pagos y filtros (aseguradora dada de baja incluida) y exportación; resumen del siniestro de lo presupuestado a lo cobrado con complementario en borrador y alertas; bloques según permiso y permisos de los reportes; reporte de siniestros con filtros, período y exportación. Los reportes existentes no cambiaron sus tests |
| Fuera de alcance | Avisos a la aseguradora (V7), M10 (deducible mayor a 0, espera G1) y M12 (retenciones, espera V17) |

# 2.72 Filtro por tipo de cliente (2026-10-30)

Pedido por la fase FA1 del frontend (aseguradoras y siniestros): el alta de una aseguradora puede vincular un cliente
empresa existente, y el buscador necesita listar solo empresas.

| Tema | Decisión |
|---|---|
| Filtro | `GET clients?kind=person|company` (decisión del usuario del 2026-10-30: en el backend, no filtrando en el frontend, porque el listado es paginado). Aditivo; un valor fuera del enum responde `422 VALIDATION_ERROR` en `kind` |
| Empresas en producción | El frontend admite clientes empresa desde FA0 (`../synctaller-frontend`, 2026-10-30): se levanta la advertencia de M1 |
| Pruebas | `ClientKindTest::test_clients_are_filtered_by_kind` |

# 2.73 Conceptos vendidos en el reparto (2026-10-30)

Pedido por la fase FA3 del frontend (reparto y facturación): el editor del borrador muestra una fila por concepto y
necesita saber cuánto debe sumar cada uno aunque el borrador guardado esté incompleto.

| Tema | Decisión |
|---|---|
| Contrato | `WorkOrderAllocationResource.concepts` (decisión del usuario del 2026-10-30: en el backend, no deduciéndolo de las porciones en el frontend). Aditivo y de solo lectura (`AllocationService::concepts`, sobre `WorkOrderSaleBreakdown`): `base_total` (R1), `coverable` (R4: ni extra ni línea del dueño) y `authorized_total` del ítem en la autorización vigente (R3, por ítem). Sin migración ni cambios de reglas |
| Pruebas | `WorkOrderAllocationTest::test_the_allocation_lists_the_sold_concepts` (suma igual a la venta, `coverable`, autorizado y extra) |

# 2.74 Discrepancias D3, D7 y D8 (2026-10-30)

Decisiones del usuario del 2026-10-30 sobre las discrepancias que quedaban abiertas (14.1). Migración `000070`, probada
en MySQL (también su `down`).

| Tema | Decisión |
|---|---|
| D3 | Se elimina `receptions.approved_by` (columna con su clave foránea, `fillable`, relación, búsqueda del listado y clave del detalle). Nunca se asignaba: siempre era `null`. Cambio de contrato: `ShowReceptionResource` ya no trae `approved_by`; el frontend solo la tenía en el tipo |
| D7 | Índice único en `clients.email`. Los dados de baja ya liberan el email con el sufijo `//deleted_` |
| D8 | `clients.document_number` y `clients.email`, `NOT NULL` (la API ya los exigía) |
| Datos existentes | La migración no inventa datos: si hay clientes sin documento o sin email, o emails repetidos, se detiene con el conteo. En la base local había uno sin email (el cliente de ejemplo del escenario C15) y se completó con el email que ahora le da el seeder |
| Ejemplos | `ClientsSeeder::WITHOUT_EMAIL` pasa a `C15_OWNER`, con email; C15 queda como un presupuesto enviado con su aviso. Los avisos omitidos de ejemplo bajan de 2 a 1 (C16, dado de baja) |
| Avisos sin email | El control de `ClientNotificationService` (omitido al registrar, `CLIENT_WITHOUT_EMAIL` al reenviar) queda como defensa, sin cambios: solo se alcanza con un email vacío, y su test usa eso |
| Pruebas | `ClientKindTest::test_the_database_requires_a_unique_email_and_the_document`, `ReceptionTest` (sin `approved_by`), `DatabaseSeederTest` y los helpers de tests que creaban clientes sin documento o sin email. Dos ajustes por el cambio: `ClaimTestCase` repetía `ana@example.com` entre los clientes de un mismo test (ahora solo el primero), y un test de recordatorios que esperaba avisos omitidos por falta de email reinicia el registro de emails antes de verificar |

# 2.75 Deducible facturado: modalidad neta (M10, 2026-10-30)

Entrega M10 de la especificación (§6.6, Ejemplo 7), con la modalidad candidata que salió de la respuesta a G1. Reglas
vigentes en `CLAUDE.md` 6.31.

| Tema | Decisión |
|---|---|
| Habilitación | Decisión del usuario del 2026-10-30: implementada y probada completa, detrás de `insurer.deductible_net_mode` (`INSURER_DEDUCTIBLE_NET_MODE`), **apagado por defecto**. La respuesta a G1 la dio un dueño de taller que lleva la contabilidad; la confirmación escrita de un contador (V21) es el requisito para encenderlo en producción |
| IVA del deducible (V15) | Decisión del usuario: se indica en cada siniestro (`deductible_includes_tax`), sin valor por defecto, como la unidad de los importes de una autorización (M6) |
| Deducible mayor que lo cubierto (V16) | Decisión del usuario: se rechaza la confirmación (`ALLOCATION_DEDUCTIBLE_EXCEEDS_COVERED`) hasta tener la respuesta |
| Factura del deducible | Decisión del usuario: en la misma factura del dueño (sigue "una vigente por cliente y versión", M9) |
| Modalidad | En el siniestro, copiada de la aseguradora al abrirse (§4.3). Apagado el interruptor, no se elige (422 de validación) y lo cargado no tiene efecto |
| Porción del deducible | `owner_deductible` sobre los mismos conceptos, descontada de lo cubierto en orden de concepto (determinista); R1/R2 sin cambios. La propuesta la arma y el editor la muestra como una columna más |
| R11 | Nombre nuevo (R10 ya era "la OT con reparto no se factura entera"): las porciones del deducible suman la base del deducible asignado a la OT (0 sin la modalidad). Se revalida al generar y al emitir las facturas: si el deducible, su IVA o la modalidad cambian después de confirmar, se genera otra versión |
| R5 | No cuenta el deducible: lo cubren el control del deducible aceptado, R7 y R11 |
| Cambios en el siniestro | Modalidad e IVA del deducible, bloqueados con una factura vigente a la aseguradora (`CLAIM_DEDUCTIBLE_LOCKED`), igual que el importe |
| Datos de ejemplo | Sin escenario: el interruptor viene apagado y `DatabaseSeederTest` no cambia |
| Migración | `000071` (columnas nulas), probada en MySQL con su `down` |
| Pruebas | `tests/Feature/Claims/DeductibleNetModeTest` (5): Ejemplo 7 (propuesta, reglas, facturas de 1.760.000 y 1.287.000 con la línea del deducible, bloqueo del siniestro y entrega), interruptor apagado (validación, regla A en factura y entrega), IVA sin indicar y sin IVA, R11 al confirmar y al facturar, deducible mayor que lo cubierto y modalidad copiada de la aseguradora. Los tests de M8 y M9 no cambiaron |
| Corrección | La revisión del frontend (2026-10-30) mostró que `deductible_fiscal_pending` seguía en `true` con la modalidad neta (el reparto y el resumen decían "no se factura"). Ahora es deducible mayor a 0 **que no se factura** (`AllocationBillingService::deductibleInvoiced`: modalidad neta en vigor y su IVA indicado). Rama `fix/deducible-fiscal-pendiente`, con su verificación en el test del Ejemplo 7 |
| Frontend | Mergeado el 2026-10-30 (`1d4d44a`, rama `feature/deducible-neto`): modalidad e IVA en el siniestro y la aseguradora, columna del deducible y reglas nuevas en el editor del reparto |
| Pendiente | V16 (respuesta), V20 (documento de la modalidad) y V21 (encender) |


# 2.76 Emails de ejemplo en mail.com.py (2026-10-30)

Los emails de ejemplo de clientes, aseguradoras (contactos), proveedores y siniestros usaban `example.com` (y `.test`), que publican un "null MX": no pasan `email:rfc,dns` con `EMAIL_DNS_CHECK=true`, y no se podía editar un siniestro de ejemplo (el email del asegurado se copia del cliente).

| Tema | Decisión |
|---|---|
| Dominio | Decisión del usuario del 2026-10-30: `mail.com.py`, como los usuarios de ejemplo. `nombre@example.com` → `nombre@mail.com.py`; `nombre@empresa.example.com` y `nombre@empresa.test` → `nombre.empresa@mail.com.py` |
| Riesgo | Es un dominio real con correo: con un SMTP configurado, un aviso reenviado de un cliente de ejemplo sale de verdad (hoy `MAIL_MAILER=log`). `NotificationSeeder` sigue usando el mailer `array` |
| Base local | Los seeders no corrigen lo que ya existe: los datos de ejemplo de la base local se actualizaron con el mismo reemplazo (clientes, asegurados de siniestros, contactos de aseguradoras y proveedores) |
| Pruebas | `DatabaseSeederTest` (email del cliente C15) |

# 2.77 Actualización de dependencias (2026-11-01)

Pedido del usuario: las versiones más actuales y compatibles en los dos repositorios, para dejar iguales sus dos equipos.

| Tema | Decisión |
|---|---|
| Backend | `composer update` dentro de los rangos de `composer.json`, sin cambiarlos: Laravel 13.39, PHPUnit 12.5.42, Symfony 7.4.22 y otros parches. `composer outdated` queda vacío y `composer audit` sin avisos |
| Frontend | `npm update` dentro de los rangos, más ApexCharts (abajo). Siguen por compatibilidad `typescript` 6.0.3 (`typescript-eslint` exige `<6.1.0`) y `@types/node` 24 (el runtime es Node 22) |
| Licencia de los gráficos | Decisión del usuario, informado del cambio: `apexcharts` 4.7.0 → 8.7.0 y `react-apexcharts` 1.7.0 → 2.2.0, en versión exacta. Ya no son MIT: licencia dual, gratis por debajo de USD 2 millones de ingresos anuales; la OEM (paga) puede aplicar a un SaaS con gráficos interactivos. **Revisar antes del primer taller comercial** |
| Efecto en el frontend | `react-apexcharts` 2 es ESM: se quitó el envoltorio de `default` en `Chart.tsx`. El paquete lazy de gráficos pasa a unos 280 kB gzip; `react-apexcharts/core` permitiría reducirlo |
| Vulnerabilidades | Producción, sin avisos en los dos repositorios. Quedan 12 moderadas en el frontend, solo de desarrollo (`minimatch`, por ESLint), sin arreglo compatible |
| Pruebas | Backend: 472 tests. Frontend: 38 tests, build y lint después de `npm ci`. Ningún test cubre los gráficos: se renderizaron los tipos en uso (barras, barras horizontales y donut, con las opciones de los reportes) en Chrome sin interfaz, sin errores, y se revisó la captura |
| Reinstalar | `composer install` y `npm ci`, nunca `npm install`: la diferencia entre los equipos era un `package-lock.json` reescrito sin cambios en `package.json` |

# 2.78 Configuración del taller en la base (T1, 2026-11-01)

Primera entrega de la línea T, la única sin validaciones pendientes. Sirve también sin SaaS. Reglas vigentes en `CLAUDE.md` 6.32.

| Tema | Decisión |
|---|---|
| Alcance | `company.*`, tasa de IVA por defecto, remitente y horarios de avisos, en `system_settings` (sin migración). Quedan afuera `invoice.decimals`, `maintenance.*`, `insurer.*` y los destinatarios internos: la especificación no los incluye |
| Valores | Solo se guarda lo cambiado; `null` vuelve al `.env`. El frontend manda solo los campos cambiados, para no fijar en la base los valores del `.env` |
| Aplicación | `config()->set`, como prevé la especificación (§3.6): el resto del código no cambia. Por request (una consulta), por job y en la tarea de recordatorios, no al arrancar: la base puede no estar disponible (`composer install`, `migrate`) |
| Worker | `Queue::before` reaplica antes de cada job, y el remitente va en el `Envelope` porque el mailer guarda el global al crearse |
| Tasa de IVA | Decisión del usuario: editable por el administrador (0, 5 o 10), con aviso previo en la pantalla y `meta.warnings`. Rige desde que se guarda, también en documentos en curso |
| Hora de los recordatorios | Decisión del usuario: en hora de Paraguay. **Cambio de comportamiento:** con el valor por defecto salían a las 08:00 UTC (05:00 de Paraguay) y ahora a las 08:00 de Paraguay. La tarea pasa a `everyMinute()` con `when()`: no se puede leer la base al cargar `routes/console.php` |
| Permiso | Se reutiliza `system-settings.update`, también para leer: los datos solo se usan en esa pantalla |
| Hallazgo | La aplicación corre en UTC: deuda #17, resuelta después (2.79) |
| Tasa de un documento ya calculado | La prueba en el navegador mostró que el PDF de un presupuesto aprobado al 10 % decía "IVA 5%" junto a su importe del 10 %: la etiqueta salía de la configuración, que hasta T1 era fija. Ahora sale de los importes guardados (`Budget::taxRate`), sin migración. El diálogo "Facturar OT" tampoco afirma ya "IVA 10%": era un texto fijo |
| Pruebas | `tests/Feature/Settings/WorkshopSettingsTest` (14): valores por defecto, reemplazo, vuelta al `.env`, tasa 0, tasa nueva en la factura y su aviso, tasa del PDF del presupuesto, totales iguales por defecto, validaciones, permisos por rol, auditoría, remitente, job, comando y hora local. Frontend: `WorkshopSettingsCard.test.tsx` (4). Revisión en Chrome (2026-11-01) sobre una copia temporal de la base (`synctaller_t1`, borrada después): error de validación, aviso de la tasa, guardado, recarga, vaciar un campo, PDF, factura al 5 % y 403 de la recepcionista, sin errores de consola |


# 2.79 Zona horaria: hora de Paraguay (deuda #17, 2026-11-01)

| Tema | Decisión |
|---|---|
| Problema | La aplicación corría en UTC: desde las 21:00 de Paraguay `now()` ya era el día siguiente. Una factura emitida a la noche salía con fecha de mañana, y el pago con la fecha real se rechazaba por "anterior a la emisión". También se movían filtros y reportes |
| Opción elegida | Decisión del usuario, entre tres (toda la aplicación en hora local; guardar UTC y calcular el día local; parche mínimo): `app.timezone` = `APP_TIMEZONE`, por defecto `America/Asuncion`. Un solo cambio corrige `whereDate`, validaciones (`today`), rangos y fechas de los Resources, sin tocar cada uso |
| Por qué no UTC | Es el estándar, pero exigía revisar unos 49 filtros, 77 formatos de fecha y 22 rangos, y cada omisión sería un error silencioso. Paraguay no tiene horario de verano (UTC-3 fijo), así que la hora local no es ambigua, y la base por taller (T2) permite que cada taller tenga su zona |
| Límite | En un país con horario de verano habría una hora ambigua por año, y un informe entre talleres de distintas zonas necesitaría convertir horas |
| Datos | Decisión del usuario: la base local solo tiene datos de ejemplo, sin conversión. Sus horas ya estaban pensadas como locales (una OT a las 17:00 se veía a las 14:00 y ahora a las 17:00) |
| API | Las fechas con hora siguen viajando en UTC (`Z`); el frontend las muestra según el navegador. Sin cambios de contrato |
| T1 | Se quitó `notifications.timezone`: ya no hace falta |
| Frontend | `AuthorizationFormModal` calculaba hoy con `toISOString()` (UTC): usa `todayInput()` |
| **Antes de T6** | Laravel escribe la hora local y las columnas `timestamp` de MySQL la convierten según la zona de la sesión. Aquí es la del sistema (UTC-3). En producción, fijar `database.connections.mysql.timezone` o `default-time-zone` en `-03:00`, y usar la misma al restaurar un respaldo |
| Pruebas | `tests/Feature/Settings/TimezoneTest` (3), con instantes absolutos en UTC: fallan con `APP_TIMEZONE=UTC`. Frontend: fecha propuesta a la noche. Revisión en Chrome sobre una copia temporal (`synctaller_tz`, borrada después): OT con sus horas correctas y factura emitida con la fecha y la hora del reloj, también en MySQL |


# 2.80 Revisión del spike T0 y decisiones para T2 (2026-11-01)

Auditoría y medición antes de T2 (`docs/spike-t0/informe.md`, sección 8). No se cambió código del producto ni se aprobó todavía
instalar la dependencia en `main`. Se llaman **TD** porque `D1–D10` ya son las discrepancias de 14.1.

| # | Decisión del usuario |
|---|---|
| TD1 | Paso 0 hecho en una rama descartable. Lo necesario para repetirlo está en `docs/spike-t0/` |
| TD2 | Paquete **4.2.3 desde el 2026-11-09**, cuando cumple dos semanas. 4.3.0 no aporta lo suficiente con un `tenants:migrate` propio. **T2 no empieza antes** y repite la medición con esa versión |
| TD3 | `tenants.domain`, sin `tenant_domains` |
| TD4 | Id entero |
| TD5 | `active` y `suspended` en T2; `provisioning` y `cancelled` en T5; el mantenimiento es un dato aparte. Sin `deleted` ni `provisioning_failed`: la fila del taller no se borra |
| TD6 | Hora de recordatorios por taller, como en T1, recorriendo los activos cada minuto |
| TD7 | Reintentos de jobs hasta 6 horas |
| TD8 | Sin `X-Tenant`: hosts reales también en local y tests |
| TD9 | Los umbrales de G no cambian; conexión persistente solo en la central |
| TD10 | `DB_DATABASE` vacío en producción: sin taller activo, una consulta falla |
| TD11 | Taller cancelado: `404 TENANT_NOT_FOUND` |

| Hallazgo | Efecto en T2 y T3 |
|---|---|
| El registro de Spatie se instancia al arrancar, antes de identificar el taller | La tarea `initializeCache()` es obligatoria también en HTTP: sin ella, los permisos se comparten entre talleres. Comprobado |
| `forgetCachedPermissions()` borra la cache | No usarla al activar un taller (corrige §3.6): suma unos 140 ms por request |
| El paquete borra el job si no encuentra el taller, antes de lanzar la excepción | Por lectura del código, no llegaría a `failed_jobs`: validar al despachar y no borrar filas de `tenants`. Verificar con un test |
| Las tareas de cambio no abren conexión, y la activación corre en `JobProcessing`, antes del middleware del job | Una tarea que consulte la base, como aplicar T1, haría fallar el job antes del middleware. Se separa cambiar (sin E/S) de preparar (`TenantRuntime::ensureReady()`) |
| `ApplyWorkshopSettings` corre en tres lugares | Se unifica en T2 |
| `$tries = 1` es incompatible con liberar el job | `retryUntil` con `maxExceptions = 1`: un error dentro de `handle` no se reintenta, porque podría duplicar el email |
| `failed_jobs` y `job_batches` usan `DB_CONNECTION` | Fijarlos en la central. También CORS para los dominios de taller |
| Sin límite de intentos en el login | Fuera de multitenancy. Resolver antes del primer taller comercial y probar el prefijo del `RateLimiter` |

Plan: pasos 1 a 3 en T2 (base central, identificación, unificar T1), 4 y 5 en T3 (jobs, scheduler) y 6 y 7 en T5
(`tenants:migrate`, suspensión). Los tests permanentes van en `tests/Feature/Tenancy` y cada uno debe fallar al quitar su
protección.

# 2.81 Skills del proyecto (2026-11-01)

Primeras Skills de Claude Code, versionadas en `.claude/skills/`. Las reglas siguen en `docs/README.md` y `CLAUDE.md`: las Skills
indican qué leer, no las repiten.

| Tema | Decisión |
|---|---|
| `retomar` | Solo lectura: informa por separado el último commit y el último checkpoint validado, y propone el siguiente paso. No corre los tests |
| `cerrar-entrega` | Solo la invoca el usuario. Se detiene si la entrega no está validada, pide confirmación si no puede determinar qué documentar y no hace commit |
| `validar-docs` | Solo lectura. Enlaces, documentos huérfanos, rutas citadas y cifras contra el código |
| Cifras | Toda cifra se compara con el código, salvo una marca explícita en su misma cláusula. Las excluidas se cuentan y `--detalle` las lista. Motivo: un filtro para evitar falsos positivos había ocultado el error de `README.md`, 45 services en vez de 47 |
| Validador en PHP | PHP es requisito del proyecto; Node es opcional en el backend. Pruebas: `.claude/skills/validar-docs/scripts/check-docs-test.php` |
| Local | `.claude/settings.local.json` queda fuera de Git |
| `medir-tenancy` | Sin crear: se decide al arrancar T2, con un caso real que medir |


---

---


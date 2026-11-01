# Arquitectura

Las convenciones obligatorias, el contrato de respuesta y las reglas de concurrencia están en `CLAUDE.md`, secciones 3 y 4. Este
documento da la visión de conjunto.

## Capas

```text
Controller (HasMiddleware) → Form Request → Service → Model/Eloquent → API Resource → ApiResponse
```

| Capa | Responsabilidad |
|---|---|
| Controller | Recibe, autoriza por permiso, llama al Service y devuelve un Resource |
| Form Request | Valida, con mensajes en español |
| Service | Reglas de negocio, transacciones y bloqueos |
| Model | Datos y relaciones. Los documentos emitidos son inmutables a nivel de modelo |
| Resource | Contrato JSON: `XResource` para listados, `ShowXResource` para detalle |
| Excepción de dominio | Error de negocio con `error_code` y código HTTP |

## Inventario del código

Contado el 2026-11-01 en `main`.

| Elemento | Cantidad |
|---|---|
| Controllers de la API | 58 |
| Services | 47 |
| Modelos | 60 |
| Enums | 57 |
| Form Requests | 117 |
| API Resources | 81 |
| Excepciones | 26 |
| Migraciones | 73 |
| Seeders | 43 |
| Rutas API | 284 |
| Tests | 489 |

## Decisiones estructurales

| Tema | Cómo funciona |
|---|---|
| Autenticación | Sanctum con tokens Bearer. `POST /api/login` y `GET /api/branding` son públicos |
| Autorización | `permission:<recurso>.<acción>` por método. `administrador` pasa todo por `Gate::before` |
| Estados | Siempre con Enum |
| Dinero | BCMath sobre strings (`App\Support\Decimal`), nunca `float` |
| Códigos internos | `DocumentSequenceService`, con bloqueo, nunca `max(id) + 1` |
| Auditoría | Trait `Auditable` y `AuditLogger`, en `audit_logs` inmutable |
| Avisos | Registro en la transacción del evento, envío por cola con `afterCommit` |
| Archivos | Disco `public` (fotos, firmas, branding) y disco privado `claim_documents`, sin URL |
| Configuración del taller | `system_settings`, aplicada sobre `config()`; el `.env` es el valor por defecto |
| Zona horaria | `America/Asuncion` en la aplicación; la API envía las fechas con hora en UTC |

## Arquitectura objetivo: SaaS

**No implementada.** Hoy hay una sola base por instalación, sin código de multitenancy. El diseño aprobado es una base central con el
registro de talleres, una base por taller y una única cola central, con la multitenencia aislada en infraestructura. Ver §3 de la
[especificación](../especificacion-aseguradoras.html), la [evaluación del paquete](../spike-t0/informe.md) y las decisiones TD1–TD11
en [`decisions/README.md`](../decisions/README.md).

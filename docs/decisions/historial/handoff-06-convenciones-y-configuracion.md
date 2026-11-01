<!-- Handoff original: convenciones y configuración (secciones 3 y 4). Líneas 2686–3146 de docs/handoff.md hasta el commit 9cae759. Mapa de secciones: docs/handoff.md. -->

# 3. Decisiones de diseño y convenciones

# 3.1 No colocar lógica de negocio compleja en Controllers

Incorrecto:

```text
Controller
 ├── validaciones de negocio
 ├── cambios de estado
 ├── transacciones
 ├── creación de múltiples modelos
 └── reglas complejas
```

Preferido:

```text
Controller
    ↓
Service
    ↓
Models
```

---

# 3.2 Services para lógica de negocio

Los Services son obligatorios para procesos que:

* cambian estados;
* modifican varias entidades;
* requieren transacciones;
* contienen reglas de negocio;
* representan operaciones del dominio.

Ejemplo:

```text
WorkOrderService
```

con operaciones:

```text
createFromBudget()
start()
pause()
resume()
complete()
cancel()
startItem()
completeItem()
cancelItem()
update()
updateItem()
```

---

# 3.3 Transacciones

Las operaciones que afectan múltiples registros deben utilizar:

```php
DB::transaction(...)
```

Esto es especialmente importante para:

```text
crear WorkOrder + items
cancelar WorkOrder + items
operaciones sobre Budget
operaciones futuras de inventario
```

---

# 3.4 Estados mediante Enum

No introducir strings arbitrarios cuando existe un estado formal.

Ejemplo:

```php
BudgetStatus::APPROVED
```

en lugar de:

```php
'approved'
```

La persistencia sigue usando los valores correspondientes, pero la lógica PHP debe preferir Enums.

---

# 3.5 Naming

Modelos:

```text
PascalCase singular
```

Ejemplo:

```text
WorkOrder
BudgetItem
Vehicle
```

Tablas:

```text
snake_case plural
```

Ejemplo:

```text
work_orders
budget_items
```

Métodos:

```text
camelCase
```

Ejemplo:

```text
createFromBudget()
startItem()
completeItem()
```

Rutas:

```text
kebab-case
```

Ejemplo:

```text
/api/work-orders
```

---

# 3.6 Resources

Los Resources controlan la representación externa de las entidades.

No exponer directamente:

```php
$model->toArray()
```

como contrato público si existe un Resource.

---

# 3.7 Listado vs detalle

Se mantiene la separación:

```text
Resource
```

para listados.

```text
ShowResource
```

para detalles.

Ejemplo:

```text
BudgetResource
ShowBudgetResource
```

---

# 3.8 Nested routes

Se utiliza:

```php
scopeBindings()
```

para rutas anidadas.

Esto permite validar correctamente relaciones como:

```text
work-order → item
```

y evita aceptar un item perteneciente a otra orden.

---

# 3.9 PATCH para actualizaciones

Las actualizaciones utilizan:

```http
PATCH
```

cuando conceptualmente son parciales.

No cambiar esta convención sin una razón arquitectónica clara.

---

# 3.10 Middleware de permisos

Los Controllers utilizan:

```php
HasMiddleware
```

con permisos específicos.

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

No reemplazar esto por una autorización global indiscriminada.

---

# 3.11 Contrato de errores

Todos los errores API deben utilizar el formato:

```json
{
    "success": false,
    "status": 422,
    "message": "...",
    "data": null,
    "errors": null,
    "error_code": "..."
}
```

No devolver directamente:

```text
exception
file
line
trace
```

en producción.

---

# 3.12 Error codes

Los mensajes son para humanos.

Los `error_code` son para el frontend.

Ejemplos:

```text
VALIDATION_ERROR
UNAUTHENTICATED
FORBIDDEN
RESOURCE_NOT_FOUND
METHOD_NOT_ALLOWED
SERVICE_UNAVAILABLE
```

Y códigos de dominio específicos:

```text
WORK_ORDER_NOT_CANCELLABLE
```

El frontend debe poder tomar decisiones utilizando `error_code`, no haciendo parsing del texto de `message`.

---

# 3.13 Mensajes

Los mensajes visibles de la API están en español.

Los nombres técnicos del código permanecen en inglés.

Ejemplo:

```text
Código:
WORK_ORDER_NOT_CANCELLABLE

Mensaje:
La orden de trabajo solo puede cancelarse cuando está pendiente.
```

---

# 3.14 No sobre-refactorizar

Una decisión importante durante el proyecto fue:

> No refactorizar arquitectura existente solamente por estilo.

Si una implementación funciona y respeta las convenciones actuales, debe mantenerse salvo que exista una razón funcional, de seguridad, rendimiento o mantenibilidad.

---

# 4. Variables de entorno y configuración

Nunca migrar secretos reales dentro del handoff.

> **Nota (verificado el 2026-10-19):** esta sección es una plantilla histórica. Los valores de referencia vigentes están
> en `.env.example` (por ejemplo `APP_URL=http://127.0.0.1:8000` y `ADMIN_PASSWORD`, la contraseña de los usuarios del
> seeder). `VITE_API_URL` pertenece al repositorio del frontend: este repositorio no la usa.

El nuevo entorno debe disponer de variables equivalentes a:

```env
APP_NAME=SyncTaller
APP_ENV=local
APP_KEY=<SECRET>
APP_DEBUG=true
APP_URL=http://localhost
```

Base de datos:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=synctaller
DB_USERNAME=<DB_USER>
DB_PASSWORD=<DB_PASSWORD>
```

Sesiones/cache/colas según configuración de Laravel:

```env
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Mail:

```env
MAIL_MAILER=<MAILER>
MAIL_HOST=<MAIL_HOST>
MAIL_PORT=<MAIL_PORT>
MAIL_USERNAME=<MAIL_USERNAME>
MAIL_PASSWORD=<MAIL_PASSWORD>
MAIL_ENCRYPTION=<MAIL_ENCRYPTION>
MAIL_FROM_ADDRESS=<MAIL_FROM>
MAIL_FROM_NAME="${APP_NAME}"
```

Frontend:

```env
VITE_API_URL=<BACKEND_API_URL>
```

Los valores concretos deben configurarse en cada entorno.

---

# 4.1 Archivos que NO deben migrarse con secretos

No incluir públicamente:

```text
.env
```

ni:

```text
API keys
passwords
tokens
APP_KEY
credenciales SMTP
credenciales de base de datos
tokens de terceros
```

Debe migrarse:

```text
.env.example
```

con valores ficticios o vacíos.

---

# 4.2 Dependencias PHP relevantes

Entre las dependencias importantes:

```text
laravel/framework
laravel/sanctum
spatie/laravel-permission
```

Además de las dependencias normales de Laravel/Composer.

Antes de continuar el desarrollo en otra herramienta:

```bash
composer install
```

y:

```bash
npm install
```

> **Nota (verificado el 2026-10-19):** `npm install` es **opcional** en este repositorio: `package.json` solo trae el
> scaffold de Vite + Tailwind de Laravel, que la API no usa (`composer setup` y `composer dev` sí lo invocan). Ver
> README, "Requisitos".

---


<!-- Handoff original: visión y arquitectura (secciones 1 a 1.5). Líneas 1–367 de docs/handoff.md hasta el commit 9cae759. Mapa de secciones: docs/handoff.md. -->

# Project Handoff Document

## SyncTaller (antes Presupuestador) — Sistema de Gestión para Taller Mecánico

**Versión del handoff:** 1.2
**Fecha:** 2026-10-17 (contenido actualizado hasta el 2026-11-01)
**Estado:** Backend/API funcional — roadmap original completo (FASES 10 a 20: repuestos, inventario, servicios,
cierre económico, facturación, pagos, entrega, historial, mantenimiento preventivo, reportes), FASE 21 (compras) y
FASE 22 (pagos a proveedores), FASE 23 (notificaciones), FASE 24 (exportación y comprobantes), FASE 25 (auditoría) y
módulo de aseguradoras en curso (M1–M11 y P10, 2.59–2.75, con M10 detrás de un interruptor apagado) y configuración del taller en la base (T1, 2.78); 6 roles; 489 tests
en verde (2026-11-01)
**Stack principal:** Laravel 13.39 (desde el 2026-10-24; antes 12.70) + MySQL (8.4 en Laragon). Frontend en React 19, en el repositorio aparte
`../synctaller-frontend` (ver 1.2)
**Entorno local:** Laragon / Windows
**Base de datos:** `synctaller` (hasta el 2026-10-21, `presupuestador`)

> **Propósito de este documento (desde la v1.2).** Es el registro histórico y de decisiones del proyecto: contexto,
> diseño por fase, decisiones de negocio y roadmap original. Hasta la v1.1 estaba en `CLAUDE.md`; en la v1.2 se movió
> aquí (con `git mv`, sin perder contenido) y `CLAUDE.md` pasó a contener solo las instrucciones operativas vigentes.
>
> * Instrucciones para trabajar en el código: [`CLAUDE.md`](../../../CLAUDE.md).
> * Instalación y uso: [`README.md`](../../../README.md).
> * Pruebas manuales por fase: [`docs/testing/guia-postman.md`](../../testing/guia-postman.md).
> * Discrepancias conocidas entre código y documentación: sección 14.
>
> Las secciones 5 y 6 (roadmap original) y 8 (checklist de migración) se conservan como historia.

---

# 1. Visión general y arquitectura

## 1.1 Propósito del proyecto

El proyecto **SyncTaller** (llamado **Presupuestador** hasta el 2026-10-21) es un sistema de gestión para un taller
mecánico.

Su objetivo es cubrir progresivamente el flujo completo de atención de un vehículo:

```text
Cliente
   ↓
Vehículo
   ↓
Recepción
   ↓
Diagnóstico
   ↓
Presupuesto
   ↓
Aprobación del presupuesto
   ↓
Orden de Trabajo
   ↓
Ejecución de trabajos
   ↓
Repuestos / materiales
   ↓
Finalización
   ↓
Entrega del vehículo
```

El proyecto comenzó con foco en la generación y gestión de presupuestos, pero evolucionó hacia un sistema más completo de gestión operativa de taller.

La arquitectura debe permitir continuar agregando módulos sin romper los módulos existentes.

---

# 1.2 Stack tecnológico

## Backend

* PHP 8.3
* Laravel 13 (hasta el 2026-10-24, Laravel 12; ver 2.51)
* Laravel Sanctum
* Laravel API Resources
* Laravel Form Requests
* Eloquent ORM
* MySQL
* Spatie Laravel Permission

## Frontend

* React 19 + TypeScript + Vite + Tabler, en el repositorio aparte `../synctaller-frontend`
  (`https://github.com/rubenminarro/synctaller-frontend.git`)
* Consumo de API REST
* Autenticación mediante Sanctum (tokens Bearer)

> **Verificado el 2026-10-17:** este repositorio no contiene código React. `package.json` es el scaffold por defecto
> de Laravel (Vite 7, Tailwind CSS 4, axios) sin uso real (`resources/views/welcome.blade.php` y `resources/js/app.js`
> sin cambios; `routes/web.php` sin rutas). Lo único preparado para un SPA es `config/cors.php` y
> `SANCTUM_STATEFUL_DOMAINS` con `http://localhost:5173` (puerto por defecto de Vite). **Actualizado el 2026-10-29
> (D4):** el frontend existe en su propio repositorio (ver arriba y su `CLAUDE.md`).

## Entorno de desarrollo

* Windows
* Laragon
* MySQL
* Composer
* Node.js / npm
* Git

---

# 1.3 Arquitectura general

El backend utiliza una arquitectura orientada a:

```text
Controller
    ↓
Form Request
    ↓
Service
    ↓
Model / Eloquent
    ↓
Database
```

Los Controllers son responsables principalmente de:

* recibir requests;
* autorizar operaciones;
* invocar Services;
* devolver Resources;
* devolver respuestas API estandarizadas.

La lógica de negocio importante debe permanecer en los Services y no ser trasladada directamente a los Controllers.

---

# 1.4 Principales capas

## Controllers

Ubicación:

```text
app/Http/Controllers/Api/
```

Responsabilidades:

* endpoints HTTP;
* autorización;
* recepción de requests;
* llamada a Services;
* transformación mediante Resources;
* respuesta API.

No deben contener lógica de negocio compleja.

---

## Requests

Ubicación:

```text
app/Http/Requests/
```

Responsabilidades:

* validación;
* reglas de entrada;
* mensajes de validación.

Cuando ya existe un Request específico para una operación, debe reutilizarse en lugar de duplicar reglas dentro del Controller.

---

## Services

Ubicación:

```text
app/Services/
```

Contienen reglas de negocio.

Ejemplos:

```text
BudgetService
WorkOrderService
```

Los Services son particularmente importantes para:

* cambios de estado;
* validaciones de negocio;
* transacciones;
* creación de entidades relacionadas;
* reglas que involucran múltiples modelos.

---

## Models

Ubicación:

```text
app/Models/
```

Representan entidades persistentes y sus relaciones Eloquent.

---

## Resources

Ubicación:

```text
app/Http/Resources/
```

Responsables de controlar el formato JSON público de la API.

Existe una diferenciación entre:

* Resource para listados;
* ShowResource para detalle.

Ejemplo:

```text
BudgetResource
ShowBudgetResource
WorkOrderResource
ShowWorkOrderResource
```

---

## Exceptions

Ubicación:

```text
app/Exceptions/
```

Actualmente existe una jerarquía orientada a errores de dominio (una excepción por módulo):

```text
AppException
├── BudgetException
├── WorkOrderException
├── WorkOrderPartException
├── PartException
├── SupplierException
├── InventoryException
├── ServiceException
├── InvoiceException
├── PaymentException
└── VehicleDeliveryException
```

---

## Enums

Ubicación:

```text
app/Enums/
```

Los estados y tipos importantes se representan mediante Enums PHP.

Esto evita trabajar indiscriminadamente con strings dispersos por el código.

---

## Traits

Ubicación:

```text
app/Traits/
```

Principalmente:

```text
ApiResponse
```

Este Trait centraliza las respuestas JSON estándar.

---

# 1.5 Estructura sugerida

La estructura lógica del backend debe mantenerse aproximadamente así:

```text
app/
├── Enums/
│   ├── BudgetStatus.php
│   ├── BudgetItemType.php
│   ├── WorkOrderStatus.php
│   ├── WorkOrderItemStatus.php
│   ├── WorkOrderItemType.php
│   └── ...
│
├── Exceptions/
│   ├── AppException.php
│   ├── BudgetException.php
│   ├── WorkOrderException.php
│   └── ...                    (una por módulo: ver 1.4)
│
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       ├── AuthController.php
│   │       ├── BudgetController.php
│   │       ├── WorkOrderController.php
│   │       ├── WorkOrderItemController.php
│   │       ├── VehicleController.php
│   │       └── ...
│   │
│   ├── Requests/
│   │   ├── LoginRequest.php
│   │   ├── StoreBudgetRequest.php
│   │   ├── UpdateBudgetRequest.php
│   │   ├── StoreWorkOrderRequest.php
│   │   ├── UpdateWorkOrderRequest.php
│   │   └── ...
│   │
│   └── Resources/
│       ├── BudgetResource.php
│       ├── ShowBudgetResource.php
│       ├── WorkOrderResource.php
│       ├── ShowWorkOrderResource.php
│       ├── WorkOrderItemResource.php
│       └── ...
│
├── Models/
│   ├── User.php
│   ├── Client.php
│   ├── Vehicle.php
│   ├── Reception.php
│   ├── Diagnostic.php
│   ├── Budget.php
│   ├── BudgetItem.php
│   ├── WorkOrder.php
│   ├── WorkOrderItem.php
│   ├── Mechanic.php
│   └── ...
│
├── Services/
│   ├── BudgetService.php
│   ├── WorkOrderService.php
│   └── ...
│
└── Traits/
    └── ApiResponse.php
```

---


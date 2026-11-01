<!-- Handoff original: checklist, principios y cierre (secciones 8 a 12). Líneas 4166–4472 de docs/handoff.md hasta el commit 9cae759. Mapa de secciones: docs/handoff.md. -->

# 8. Checklist para continuar en otra herramienta

> Plantilla para configurar el proyecto en una máquina nueva (no es un estado del proyecto). Los pasos concretos de
> instalación están en el [`README.md`](../../../README.md). La sección "Frontend" aplica al repositorio del frontend, que no
> forma parte de este.

## Backend

```text
[ ] Laravel 12 instalado
[ ] PHP compatible
[ ] Composer instalado
[ ] MySQL configurado
[ ] .env configurado
[ ] APP_KEY generado
[ ] migrations ejecutadas
[ ] seeders ejecutados
[ ] Sanctum funcionando
[ ] Spatie Permission funcionando
[ ] API respondiendo
```

## Frontend

```text
[ ] Node.js instalado
[ ] npm install
[ ] VITE_API_URL configurada
[ ] login funcionando
[ ] token/session funcionando
[ ] consumo de API funcionando
```

## Validación

```text
[ ] Login
[ ] Permissions
[ ] Client
[ ] Vehicle
[ ] Reception
[ ] Diagnostic
[ ] Budget
[ ] BudgetItem
[ ] WorkOrder
[ ] WorkOrderItem
[ ] Exceptions
[ ] HTTP status codes
```

---

# 9. Regla principal para el nuevo desarrollador/agente

Antes de implementar cualquier módulo nuevo:

1. Leer este documento completo.
2. Revisar las entidades existentes.
3. Revisar los Enums.
4. Revisar Services.
5. Revisar Resources.
6. Revisar Requests.
7. Revisar permisos.
8. Revisar excepciones.
9. Revisar rutas existentes.
10. No modificar contratos existentes sin justificarlo.

---

# 10. Principio arquitectónico futuro

El sistema debe evolucionar desde:

```text
Presupuestador
```

hacia:

```text
Sistema integral de gestión de taller
```

La arquitectura objetivo es:

```text
                    ┌──────────────┐
                    │    Cliente   │
                    └──────┬───────┘
                           │
                    ┌──────▼───────┐
                    │   Vehículo   │
                    └──────┬───────┘
                           │
                    ┌──────▼───────┐
                    │  Recepción   │
                    └──────┬───────┘
                           │
             ┌─────────────┼─────────────┐
             │             │             │
       Diagnóstico    Presupuesto     Checklist
             │             │
             │        ┌────▼─────┐
             │        │ Aprobado │
             │        └────┬─────┘
             │             │
             │       ┌─────▼──────┐
             └──────►│ WorkOrder  │
                     └─────┬──────┘
                           │
              ┌────────────┼────────────┐
              │            │            │
           Servicios     Mano obra   Repuestos
              │            │            │
              │            │       ┌────▼──────┐
              │            │       │ Inventario│
              │            │       └────┬──────┘
              │            │            │
              └────────────┼────────────┘
                           │
                    ┌──────▼───────┐
                    │   Cierre     │
                    └──────┬───────┘
                           │
                    ┌──────▼───────┐
                    │  Facturación │
                    └──────┬───────┘
                           │
                    ┌──────▼───────┐
                    │    Pago      │
                    └──────┬───────┘
                           │
                    ┌──────▼───────┐
                    │   Entrega    │
                    └──────────────┘
```

---

# 11. Punto exacto donde continuar

El proyecto actualmente se encuentra en:

```text
MÓDULO DE ASEGURADORAS — M1 a M11 Y P10 IMPLEMENTADAS (ver 2.59–2.75; M10 APAGADA, M11 SIN LOS AVISOS); SIGUE M12
```

Con la FASE 20 se completó el roadmap original (FASES 10–20); después se implementaron compras (FASE 21), pagos a
proveedores (FASE 22), notificaciones por email y bandeja interna (FASE 23), exportación de reportes y comprobantes
imprimibles (FASE 24) y auditoría avanzada (FASE 25). Desde el 2026-10-24 se trabaja en el módulo de aseguradoras
(deuda #13), según el plan de `docs/especificacion-aseguradoras.html` (§9): M1–M9, P10, M10 (detrás de un interruptor
apagado, en backend y frontend) y M11 (sin los avisos) están hechas; quedan encender M10 (confirmación escrita del
contador, V21, y V16), M12 (retenciones: aprobar el diseño y V17) y los avisos a la aseguradora (decisión comercial, V7). El frontend React 19 está en
`../synctaller-frontend` y se adapta en su propio repositorio. Sigue sin fase asignada:

```text
Notificaciones por WhatsApp (el canal ya está previsto en NotificationChannel)
```

El orden de trabajo de abajo se usó para las FASES 10 a 13 y sigue siendo la referencia.

Pero la implementación debe comenzar primero por el **diseño funcional y de datos**, no directamente por el CRUD.

Orden recomendado:

```text
10.1 Definir catálogo de repuestos
      ↓
10.2 Definir categorías
      ↓
10.3 Definir proveedores
      ↓
10.4 Definir modelo de inventario
      ↓
10.5 Definir movimientos
      ↓
10.6 Definir relación WorkOrder ↔ repuestos
      ↓
10.7 Definir impacto económico
      ↓
10.8 Implementar migrations
      ↓
10.9 Models / Enums
      ↓
10.10 Services
      ↓
10.11 Requests
      ↓
10.12 Resources
      ↓
10.13 Controllers
      ↓
10.14 Permissions
      ↓
10.15 Exceptions
      ↓
10.16 Tests / regresión
```

La prioridad es **no crear un CRUD aislado de repuestos**, sino diseñar correctamente el vínculo entre:

```text
Catálogo
    +
Inventario
    +
WorkOrder
    +
Costos
    +
Precio de venta
```

para que el sistema pueda crecer posteriormente hacia compras, facturación y rentabilidad.

---

# 12. Estado final del handoff

## Construido

```text
[✓] Autenticación
[✓] Usuarios
[✓] Roles
[✓] Permisos
[✓] Clientes
[✓] Vehículos
[✓] Modelos de vehículos
[✓] Recepciones
[✓] Categorías de servicio
[✓] Checklist
[✓] Diagnóstico
[✓] Mecánicos
[✓] Presupuestos
[✓] Items de presupuesto
[✓] Estados de presupuesto
[✓] Orden de trabajo
[✓] Items de orden de trabajo
[✓] Estados de WorkOrder
[✓] Transiciones
[✓] API Resources
[✓] API Response
[✓] Exceptions
[✓] Error Codes
[✓] HTTP status standardization
[✓] Auditoría de Controllers
[✓] FASE 9.14
[✓] Catálogo de repuestos (FASE 10)
[✓] Categorías de repuestos (FASE 10)
[✓] Proveedores (FASE 10)
[✓] Inventario (FASE 11)
[✓] Movimientos de inventario (FASE 11)
[✓] Repuestos utilizados en WorkOrder (FASE 12)
[✓] Catálogo de servicios (FASE 13)
[✓] Asignación de mecánicos y comisiones (FASE 13)
[✓] Tiempo estimado, horas reales y costo del mecánico — opcionales (FASE 13)
[✓] Cierre económico, margen y liquidación de comisiones (FASE 14)
[✓] Facturación con timbrado e IVA incluido (FASE 15)
[✓] Pagos, recibos y resumen de caja (FASE 16)
[✓] Entrega del vehículo con comprobante y conformidad (FASE 17)
[✓] Roles del taller: recepcionista, jefe-taller, cajero, depósito
[✓] Historial del vehículo con resumen, visitas, repuestos y kilometraje (FASE 18)
[✓] Mantenimiento preventivo con planes, km estimado y alertas (FASE 19)
[✓] Reportes y dashboard por área (FASE 20)
[✓] Compras con IVA incluido e ingreso de stock (FASE 21)
[✓] Pagos a proveedores y cuentas por pagar (FASE 22)
[✓] Notificaciones por email y bandeja interna (FASE 23)
[✓] Exportación de reportes (CSV/PDF) y comprobantes imprimibles (FASE 24)
[✓] Auditoría avanzada de precios, facturación, seguridad y datos maestros (FASE 25)
[✓] Preferencias visuales por usuario y logo del sistema (2.50)
[✓] Catálogo de ítems de diagnóstico (2.53)
[✓] Recordatorios de factura al destinatario correcto (2.54)
[✓] Códigos internos con bloqueo (2.55)
[✓] Importes del presupuesto en decimal(14,2) (2.56)
[✓] Registro de la respuesta del cliente al presupuesto (2.57)
[✓] Spike T0 de tenancy, descartado; informe en docs/spike-t0/informe.md (2.58)
[✓] Cliente persona o empresa (2.59)
[✓] Aseguradoras y sus contactos (2.60)
[✓] Siniestros (2.61)
[✓] Documentos privados de siniestros (2.62)
[✓] Responsable por línea del presupuesto (2.63)
[✓] Autorizaciones de la aseguradora (2.64)
[✓] Bloqueo de la OT, re-aceptación y excepciones internas (2.65)
[✓] Reparto económico de la OT de un siniestro (2.68)
[✓] Notas de crédito emitidas fuera del sistema (2.69)
[✓] Facturas por responsable (2.70)
[✓] Reportes del módulo de aseguradoras (2.71)
[✓] Filtro por tipo de cliente (2.72)
[✓] Conceptos vendidos en el reparto (2.73)
[✓] Discrepancias D3, D7 y D8 (2.74)
[✓] Deducible facturado, modalidad neta: hecha y apagada (2.75)
[✓] Configuración del taller en la base (T1, 2.78)
[✓] Hora de Paraguay en toda la aplicación (2.79)
```

## Próximo

```text
[ ] Aseguradoras: encender M10 (V21 y V16), retenciones (M12: aprobar el diseño y V17) y avisos a la aseguradora (V7)
[ ] Notificaciones por WhatsApp
```

El frontend React 19 está en `../synctaller-frontend` (no es una tarea de este repositorio).

---


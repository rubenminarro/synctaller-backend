<!-- Principios del proyecto (sección 13 del handoff original). Líneas 4473–4527 de docs/handoff.md hasta el commit 9cae759. Mapa de secciones: docs/handoff.md. -->

# 13. Regla de oro del proyecto

> **No diseñar cada módulo como una funcionalidad aislada.**

Cada nueva entidad debe analizarse considerando:

```text
¿Quién la crea?
¿Quién la modifica?
¿Quién la utiliza?
¿En qué estado puede estar?
¿Qué entidades dependen de ella?
¿Qué impacto económico tiene?
¿Qué pasa si se cancela?
¿Qué pasa si se elimina?
¿Qué permisos requiere?
¿Qué error_code necesita?
¿Cómo afecta al flujo de la WorkOrder?
¿Cómo afectará a facturación e inventario?
```

El objetivo final no es simplemente tener muchos CRUDs.

El objetivo es construir un **sistema coherente de gestión de taller**, donde:

```text
Recepción
    ↓
Diagnóstico
    ↓
Presupuesto
    ↓
Aprobación
    ↓
Orden de trabajo
    ↓
Ejecución
    ↓
Repuestos + Mano de obra
    ↓
Cierre
    ↓
Facturación
    ↓
Pago
    ↓
Entrega
    ↓
Historial
```

mantenga integridad de datos, reglas de negocio claras y una API consistente durante toda la evolución del sistema.

---


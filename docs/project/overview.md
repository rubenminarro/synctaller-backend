# Qué es SyncTaller

SyncTaller es un sistema de gestión para talleres mecánicos. Cubre la atención de un vehículo de punta a punta, con foco en la
trazabilidad de lo presupuestado, lo ejecutado, lo facturado y lo cobrado. Se llamaba **Presupuestador** hasta el 2026-10-21.

```text
Cliente → Vehículo → Recepción → Diagnóstico → Presupuesto → Aprobación → Orden de trabajo
       → Ejecución (servicios, mano de obra, repuestos) → Cierre económico → Factura → Pago → Entrega → Historial
```

Alrededor de ese flujo están el inventario y las compras, los proveedores, el mantenimiento preventivo, los reportes, los avisos a
clientes y la auditoría. La línea en curso agrega las reparaciones por siniestro, con aseguradoras, y prepara la operación como
SaaS, con varios talleres.

## Repositorios

| Repositorio | Contenido | Ubicación local esperada |
|---|---|---|
| [`synctaller-backend`](https://github.com/rubenminarro/synctaller-backend) | API REST en Laravel | `../synctaller-backend` |
| [`synctaller-frontend`](https://github.com/rubenminarro/synctaller-frontend) | Interfaz en React | `../synctaller-frontend`, carpeta hermana |

`package.json` y `resources/` del backend son el scaffold de Laravel, sin uso salvo las vistas de email y de PDF.

## Usuarios del sistema

Seis roles: `administrador`, `mechanic`, `recepcionista`, `jefe-taller`, `cajero` y `deposito`. Sus accesos están en `CLAUDE.md`,
sección 5.

## Contexto de negocio

* País: Paraguay. Moneda: guaraníes, sin decimales en lo facturado.
* IVA del 10 %, 5 % o exento, con factura con timbrado. SIFEN no es obligatorio todavía (según la respuesta a G5, sin confirmar por
  un contador).
* Las reglas fiscales no se inventan: se validan con un contador. Las validaciones pendientes están en §10 de la
  [especificación](../especificacion-aseguradoras.html).

## Documentos relacionados

* [Principios del proyecto](principios.md)
* [Arquitectura](architecture.md)
* [Estado por módulo](../modules/README.md)

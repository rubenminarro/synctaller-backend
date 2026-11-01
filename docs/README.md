# Documentación de SyncTaller

Punto de entrada de la documentación del backend. **Para retomar el desarrollo, empezar por [`handoff.md`](handoff.md).**

## Dónde está cada cosa

| Quiero saber… | Documento |
|---|---|
| Dónde está el proyecto y cuál es el siguiente paso | [`handoff.md`](handoff.md) |
| Qué es el proyecto | [`project/overview.md`](project/overview.md) |
| Cómo está construido | [`project/architecture.md`](project/architecture.md) |
| Qué tecnologías y versiones usa | [`project/technology-stack.md`](project/technology-stack.md) |
| Qué principios guían el diseño | [`project/principios.md`](project/principios.md) |
| Qué módulos están terminados y cuáles no | [`modules/README.md`](modules/README.md) |
| Qué se hizo y qué falta, por fase | [`roadmap/README.md`](roadmap/README.md) |
| Qué deudas y discrepancias siguen abiertas | [`roadmap/deuda-tecnica.md`](roadmap/deuda-tecnica.md) |
| Qué se decidió y por qué | [`decisions/README.md`](decisions/README.md) |
| Cómo instalar en una computadora nueva | [`development/setup.md`](development/setup.md) |
| Cómo correr los tests | [`development/testing.md`](development/testing.md) |
| Qué se sabe del despliegue | [`development/deployment.md`](development/deployment.md) |
| Cómo probar la API a mano | [`testing/guia-postman.md`](testing/guia-postman.md) |
| Diseño del módulo de aseguradoras y de la línea SaaS | [`especificacion-aseguradoras.html`](especificacion-aseguradoras.html) |
| Evaluación del paquete de multitenancy | [`spike-t0/informe.md`](spike-t0/informe.md) |

## Fuente de verdad de cada tema

Cada dato vive en un solo lugar. Los demás documentos enlazan, no copian.

| Tema | Fuente de verdad |
|---|---|
| Comportamiento real | El código y los tests. Si un documento lo contradice, manda el código: registrar la discrepancia en [`roadmap/deuda-tecnica.md`](roadmap/deuda-tecnica.md) |
| Reglas operativas, convenciones y reglas de negocio vigentes | [`CLAUDE.md`](../CLAUDE.md) |
| Instalación detallada, variables de entorno y datos de prueba | [`README.md`](../README.md) de la raíz |
| Estado de cada módulo | [`modules/README.md`](modules/README.md) |
| Estado de cada fase y entrega | [`roadmap/README.md`](roadmap/README.md) |
| Punto de continuidad | [`handoff.md`](handoff.md) |
| Deuda técnica | [`roadmap/deuda-tecnica.md`](roadmap/deuda-tecnica.md) |
| Decisiones, con su motivo | [`decisions/README.md`](decisions/README.md) y lo que enlaza |
| Diseño pendiente de implementar y validaciones externas | [`especificacion-aseguradoras.html`](especificacion-aseguradoras.html), §9 y §10 |
| Contrato manual de la API | [`testing/guia-postman.md`](testing/guia-postman.md) |

## Estructura

```text
docs/
├── README.md                       Este índice
├── handoff.md                      Documento principal para retomar y mapa de secciones antiguas
├── especificacion-aseguradoras.html  Diseño vigente de aseguradoras y SaaS (versión 3)
├── project/                        Qué es y cómo está construido
├── modules/                        Estado por módulo
├── roadmap/                        Estado por fase y deuda técnica
├── decisions/                      Índice de decisiones e historial
├── development/                    Instalación, tests y despliegue
├── testing/                        Guía de pruebas manuales con Postman
└── spike-t0/                       Evaluación de multitenancy y herramientas de medición
```

Tres rutas **no se pueden mover** sin actualizar referencias externas: `handoff.md`, `especificacion-aseguradoras.html` y
`testing/guia-postman.md`. Las citan `CLAUDE.md`, el `README.md` raíz, un test y el `CLAUDE.md` del frontend.

## Mantenimiento

| Al… | Actualizar |
|---|---|
| Terminar una entrega | [`handoff.md`](handoff.md) (punto de continuidad), [`modules/README.md`](modules/README.md) y [`roadmap/README.md`](roadmap/README.md) |
| Tomar una decisión | Una sección nueva `2.N` en [`handoff-05-decisiones-por-entrega.md`](decisions/historial/handoff-05-decisiones-por-entrega.md) |
| Detectar una deuda o discrepancia | [`roadmap/deuda-tecnica.md`](roadmap/deuda-tecnica.md) |
| Cambiar la API | [`testing/guia-postman.md`](testing/guia-postman.md) |
| Cambiar una regla de negocio | `CLAUDE.md`, sección 6 |

Los archivos de `decisions/historial/` son registro histórico: no se corrigen para reflejar el estado actual. La única
excepción es `handoff-05-decisiones-por-entrega.md`, que sigue creciendo con cada entrega.

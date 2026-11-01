---
name: cerrar-entrega
description: Actualiza la documentación de una entrega ya validada, según las reglas de docs/README.md.
disable-model-invocation: true
argument-hint: "[nombre de la entrega]"
allowed-tools: Read Grep Glob Bash(git status *) Bash(git log *) Bash(git diff *) Bash(git branch --show-current) Bash(php .claude/skills/validar-docs/scripts/check-docs.php*)
---

# Cerrar una entrega

Entrega a cerrar: $ARGUMENTS

Registrar en la documentación lo que realmente se hizo. **Solo documenta: no toca código, no corrige lo que encuentre y no hace commit,
push ni merge.**

## 1. Comprobar que la entrega esté validada

Antes de editar, confirmar cada punto. Si alguno falta, **detenerse e informar qué falta**.

* Se está en una rama de trabajo, no en `main`.
* La suite completa pasó sobre el código actual, en esta sesión o según un resultado que el usuario haya indicado. No suponerlo ni
  deducirlo de los commits.
* El usuario confirmó que la entrega está validada, si requiere revisión manual.

Sin entrega indicada en los argumentos, preguntar cuál es.

## 2. Reunir los hechos

Leer `docs/README.md`, secciones **Fuente de verdad de cada tema** y **Mantenimiento**, y `CLAUDE.md`, sección 2, **Documentación por
cambio**. Esas reglas mandan: esta Skill no las repite.

Con `git log` y `git diff origin/main...HEAD`, y con los datos de la conversación, listar qué cambió, qué se decidió y quién lo decidió.

## 3. Definir qué actualizar

Armar la lista de documentos a actualizar y el motivo de cada uno.

**Si no se puede determinar con certeza qué documentación corresponde, pedir confirmación al usuario antes de editar.** Por ejemplo: un
cambio que no encaja en la tabla de Mantenimiento, dudas sobre si algo es una decisión o una deuda, o reglas que parecen contradecirse.
Mostrar la lista propuesta y qué es lo dudoso. No resolverlo por cuenta propia.

## 4. Actualizar

| Regla | Detalle |
|---|---|
| No inventar | Ningún estado, fase, decisión, fecha ni resultado que no esté respaldado por el código, los tests o el usuario |
| Sin certeza sobre un dato | Usar `PENDIENTE DE VERIFICACIÓN`, o preguntar |
| Numeración | Mantener las fases. La sección nueva toma el número siguiente al último `2.N` de `handoff-05-decisiones-por-entrega.md` |
| Cifras | Tests, rutas y commits salen de lo verificado, nunca de memoria |
| Una fuente | Actualizar donde vive cada dato y enlazar desde el resto |
| Historial | No corregir `docs/decisions/historial/`, salvo agregar secciones a `handoff-05` |
| `CLAUDE.md` | Solo si cambió una regla, y solo esa regla |
| Roadmap | Solo cambia el estado de lo entregado. Sin fases nuevas sin aprobación |

## 5. Verificar

Desde la raíz del repositorio:

```bash
php .claude/skills/validar-docs/scripts/check-docs.php
```

Corregir los enlaces rotos que hayan causado estos cambios. Informar los demás hallazgos sin tocarlos.

## 6. Informe

Mostrar los archivos modificados y qué cambió en cada uno, la evidencia usada, lo que quedó como `PENDIENTE DE VERIFICACIÓN` y el
resultado de la verificación. Dejar los cambios sin commitear: el commit, el push y el merge los autoriza el usuario.

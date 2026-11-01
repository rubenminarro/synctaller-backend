---
name: retomar
description: Informa dónde está el proyecto y propone el siguiente paso. Usar al empezar una sesión o cuando se pida retomar o continuar el trabajo. Solo lectura.
allowed-tools: Read Grep Glob Bash(git status *) Bash(git log *) Bash(git fetch *) Bash(git rev-parse *) Bash(git rev-list *) Bash(git diff --stat *) Bash(git branch --show-current) Bash(git -C ../synctaller-frontend status *) Bash(git -C ../synctaller-frontend log *) Bash(git -C ../synctaller-frontend fetch *) Bash(git -C ../synctaller-frontend rev-list *)
disallowed-tools: Edit Write NotebookEdit
---

# Retomar el proyecto

Reunir el estado real y proponer el siguiente paso. **No modificar archivos, no correr la suite de tests y no crear ramas.**
`git fetch` es lo único que actualiza algo, y solo las referencias remotas.

## 1. Estado de Git

Desde la raíz del repositorio:

```bash
git fetch origin
git status -sb
git log --oneline -5
git rev-list --left-right --count HEAD...origin/main
```

Hacer lo mismo en `../synctaller-frontend` con `git -C`. Si esa carpeta no existe, indicarlo y seguir: no es un error.

## 2. Último commit y último checkpoint validado

Son dos datos distintos y hay que informar los dos:

| Dato | Qué es | De dónde sale |
|---|---|---|
| **Último commit** | Lo más reciente del repositorio. No implica que esté probado | Git |
| **Último checkpoint validado** | El último commit con un resultado de tests registrado | `docs/handoff.md`, sección **Punto de continuidad** |

Leer esa sección y **Próximas tareas**. No copiar su contenido a esta Skill. Para cada commit citado ahí:

* Comprobar que exista con `git rev-parse --verify <commit>`.
* Listar lo posterior con `git log --oneline <commit>..origin/main`.
* Clasificarlo con `git diff --stat <commit> origin/main`: solo documentación, o también código.

El último commit nunca se presenta como validado por el solo hecho de ser el último. Si hay código posterior al checkpoint, ese código
**no está validado por el handoff**: decirlo, sin suponer que los tests pasan. Si el handoff no registra un resultado de tests, informar
que no se pudo determinar el checkpoint.

## 3. Bloqueos

Revisar cada tarea de **Próximas tareas**. Comparar las fechas de bloqueo con la fecha actual. Si hace falta el detalle de una tarea,
consultar `docs/roadmap/README.md` o `docs/modules/README.md`, según `docs/README.md`.

## 4. Informe

Responder con este formato:

```text
Estado de Git     Rama, cambios sin commitear y diferencia con origin/main, por repositorio
Último commit     Hash y mensaje, por repositorio
Checkpoint        Último commit validado, con fecha y tests registrados
Desde entonces    Commits posteriores: solo documentación, o con código sin validar
Próximas tareas   Las del handoff, con su bloqueo vigente
Siguiente paso    Una propuesta concreta, con su motivo
```

Avisar si algo no coincide: cambios sin commitear, ramas detrás de `origin/main`, un commit citado que no existe o un handoff
desactualizado. No corregirlo: proponerlo.

Terminar con la propuesta y esperar la decisión del usuario. Ninguna entrega empieza sin su autorización.

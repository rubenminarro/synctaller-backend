---
name: validar-docs
description: "Audita la documentación: enlaces internos, documentos huérfanos, referencias desactualizadas y cifras contra el código. Usar al revisar docs o antes de cerrar una entrega. Solo informa."
allowed-tools: Read Grep Glob Bash(php .claude/skills/validar-docs/scripts/check-docs.php*) Bash(git log *) Bash(git rev-parse *)
disallowed-tools: Edit Write NotebookEdit
---

# Validar la documentación

Auditar e informar. **Es de solo lectura: no modificar, crear ni borrar archivos, ni ejecutar comandos que lo hagan.** Corregir lo
encontrado es un pedido aparte del usuario.

## 1. Comprobaciones automáticas

Desde la raíz del repositorio:

```bash
php .claude/skills/validar-docs/scripts/check-docs.php --detalle
```

El script revisa enlaces y anclas, documentos huérfanos desde `docs/README.md`, rutas citadas que no existen y las cifras de los documentos
vivos contra el código. Código de salida 1 si hay hallazgos.

Toda cifra se compara salvo una marca explícita en su misma cláusula: datos históricos, de otro proyecto, ejemplos o diferencias. La cifra
de una clase de test se valida contra esa clase. **Revisar la lista de excluidas de `--detalle`:** una exclusión equivocada es un hallazgo.

## 2. Revisión de consistencia

Lo que un script no puede juzgar. Comparar según las fuentes de verdad de `docs/README.md`:

* `docs/handoff.md`: que el punto de continuidad exista en Git y que **Próximas tareas** coincida con el estado de `docs/roadmap/README.md`.
* `docs/modules/README.md` y `docs/roadmap/README.md`: que no se contradigan, y que lo marcado `COMPLETADO` o `PENDIENTE` coincida con el
  código. Buscar en rutas, modelos y tests; no fiarse de otro documento.
* `CLAUDE.md`, sección 7, contra `docs/roadmap/deuda-tecnica.md`: las mismas deudas abiertas.

`docs/decisions/historial/` es registro histórico: su contenido viejo no es un hallazgo, salvo que un documento vivo se apoye en él. La
excepción son los enlaces, que sí deben funcionar.

## 3. Informe

Agrupar por gravedad e indicar archivo y línea.

| Gravedad | Qué incluye |
|---|---|
| Error | Enlaces rotos, documentos huérfanos, rutas inexistentes, cifras que no coinciden |
| Contradicción | Dos documentos vivos dicen cosas distintas, o un documento contradice al código |
| Aviso | Datos sospechosos que no se pudieron confirmar |

Si no hay hallazgos, decirlo y mostrar lo que se revisó. Indicar siempre lo que no se pudo comprobar.

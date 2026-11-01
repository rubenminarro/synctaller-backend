# Paso 0 del spike T0: cómo repetir la medición

Código y scripts de la medición del 2026-11-01 (`docs/spike-t0-informe.md`, sección 8). **Es material de referencia, no código
del producto: no se copia tal cual a T2.** Incluye marcas de tiempo y variantes solo para medir.

| Archivo | Contenido |
|---|---|
| `spike.diff` | Cambios de la rama descartable sobre `18e2bfa`, sin `composer.lock`. Falta crear a mano las tablas `tenants`, `cache` y `cache_locks` en la central |
| `nginx.conf`, `up.sh` | Nginx y `php-cgi` propios por puerto, con OPcache, sin tocar Laragon. La ruta es un ejemplo |
| `measure.php` | Cliente: intercala las variantes al azar en cada ciclo |
| `analyze.php` | Resumen: p50, p95, diferencia pareada y etapas |
| `resultado-limpio.txt` | Salida de la corrida que usa el informe |

Para repetirla: dos copias de trabajo (`main` y la rama con el diff), una base por copia y la central, un token del
administrador en `token-base.txt` y `token-spike.txt`, y `php measure.php <carpeta> 300`.

No medir dos variantes que compartan taller y cache si una de ellas borra la cache: una contamina a la otra.

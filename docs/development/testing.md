# Tests

## Ejecutar

```bash
php artisan test                                  # suite completa, unos 6 minutos
php artisan test tests/Feature/Claims             # una carpeta
php artisan test --filter=test_nombre             # un test
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit   # si pdo_sqlite está deshabilitado
```

**No correr dos ejecuciones a la vez:** comparten `database/testing.sqlite`.

Frontend, desde su repositorio:

```bash
npm test
npm run lint
npm run build
```

## Cómo está armada la suite

* SQLite en archivo (`database/testing.sqlite`): migra y siembra roles y permisos una vez por ejecución, y cada test corre en una
  transacción.
* No necesita red: `phpunit.xml` fija `EMAIL_DNS_CHECK=false`, y `uncompromised()` se simula con `Http::fake`.
* Cola `sync` y mail `array`; `Mail::fake()` para verificar envíos.

## Cobertura por carpeta

Contado el 2026-11-01: 489 tests. La relación con cada módulo está en [`modules/README.md`](../modules/README.md).

| Carpeta | Tests | Carpeta | Tests |
|---|---|---|---|
| `Access` | 19 | `Maintenance` | 7 |
| `Audit` | 6 | `MasterData` | 31 |
| `Auth` | 9 | `Notifications` | 17 |
| `Budgets` | 23 | `Parts` | 30 |
| `Claims` | 52 | `Payments` | 11 |
| `Clients` | 16 | `Preferences` | 7 |
| `Deliveries` | 10 | `Purchases` | 16 |
| `Documents` | 11 | `Receptions` | 39 |
| `Http` | 6 | `Reports` | 11 |
| `Insurers` | 7 | `Roles` | 2 |
| `Inventory` | 25 | `Seeders` | 1 |
| `Invoices` | 19 | `Services` | 23 |
| `Settings` | 32 | `Vehicles` | 6 |
| `WorkOrderParts` | 33 | `WorkOrders` | 19 |
| `Unit` | 1 | | |

## Criterios para tests nuevos

| Regla | Motivo |
|---|---|
| Permisos: probar con un rol propio | El administrador pasa todo por `Gate::before` y un nombre mal escrito no se nota |
| Un test debe fallar sin su corrección | Comprobarlo una vez. Dos tests de horario pasaban también con el error |
| Horarios: simular un instante absoluto en UTC | "Las 22:00" en la zona de la aplicación pasaría también con la zona equivocada |
| Archivos subidos: usar archivos reales | `UploadedFile::fake()` informa el tipo según la extensión |
| Nuevo estado en un enum: agregar su escenario | Y su verificación en `DatabaseSeederTest` |

## Lo que la suite no cubre

| Tema | Cómo se verifica |
|---|---|
| Concurrencia real | Solo en MySQL: `tests/Manual/document_sequence_concurrency.php` |
| Migraciones en MySQL | A mano: los nombres de índices largos no fallan en SQLite |
| Gráficos del frontend | Sin tests. Se revisan en un navegador |

Pruebas manuales de la API: [`testing/guia-postman.md`](../testing/guia-postman.md).

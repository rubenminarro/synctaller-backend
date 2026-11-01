# Instalación en una computadora nueva

El detalle de cada variable de entorno y los datos de prueba están en el [`README.md`](../../README.md) de la raíz. Este documento
separa lo que exige el proyecto de lo que es propio de cada instalación.

## Requisitos del proyecto

Valen en cualquier equipo.

| Requisito | Detalle |
|---|---|
| PHP | 8.3 o superior |
| Extensiones de PHP | `pdo_mysql`, `pdo_sqlite` y `sqlite3` (tests), `bcmath`, `mbstring`, `gd` (imágenes en PDF), `zip` (Composer), `fileinfo`, `openssl`, `curl`, `dom` |
| Composer | 2 |
| MySQL | 8, con base en `utf8mb4` |
| Node.js y npm | 22 o superior, para el frontend. El backend no los necesita |
| Git | Acceso a los dos repositorios |

Sin `zip` en el PHP de consola, Composer clona los paquetes uno por uno y la instalación tarda muchísimo.

## Instalación

```bash
git clone https://github.com/rubenminarro/synctaller-backend.git
cd synctaller-backend
composer install
cp .env.example .env
php artisan key:generate
```

Completar el `.env`: conexión a MySQL, `ADMIN_PASSWORD`, `TEST_PASSWORD` y las variables que indica el `README.md`. **Las contraseñas no
van en el repositorio ni en esta documentación.**

Crear la base y sembrarla:

```sql
CREATE DATABASE synctaller CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate --seed     # solo sobre una base vacía; tarda unos 15 s
php artisan test               # debe dar todos los tests en verde
```

Frontend, en una carpeta hermana:

```bash
git clone https://github.com/rubenminarro/synctaller-frontend.git
cd synctaller-frontend
npm ci
npm run dev
```

Su `.env` define `VITE_API_URL`. CORS del backend solo admite `http://localhost:5173`.

## Actualizar un equipo ya instalado

```bash
git pull
composer install
php artisan migrate
php artisan db:seed --class=RolesAndPermissionsSeeder   # si la entrega agregó permisos
```

En el frontend: `git pull` y `npm ci`.

| No usar | Motivo |
|---|---|
| `npm install` | Reescribe `package-lock.json` y separa los equipos |
| `php artisan config:cache` en desarrollo | Los tests correrían contra MySQL y podrían borrar la base |
| `migrate:fresh`, `migrate:refresh`, `db:wipe` | Borran todos los datos |

## Procesos para los avisos

Sin ellos, los avisos quedan `pending` y los recordatorios no se generan.

```bash
php artisan queue:work      # envía los avisos
php artisan schedule:run    # cada minuto
```

En desarrollo, `MAIL_MAILER=log` escribe los emails en `storage/logs/laravel.log`.

## Particularidades de una instalación local

**No son requisitos del proyecto.** Describen los equipos de desarrollo usados hasta ahora, y otro equipo puede ser distinto.

| Tema | Situación conocida |
|---|---|
| Sistema | Windows con Laragon |
| Servidor web | Nginx de Laragon en el puerto 80, con `php-cgi`. **No usar Apache:** requests concurrentes corrían sin el `.env` (deuda #12) |
| Dominio local | `http://synctaller-backend.test`. Con `php artisan serve`, `http://127.0.0.1:8000` |
| Rutas | `C:\laragon\www\synctaller-backend` y `synctaller-frontend` |
| OPcache | Activado en `php-cgi` en una de las notebooks y apagado en la otra. Acelera mucho las respuestas y no afecta a los tests |
| `php-cgi` | Laragon lo reinicia cada 500 requests; mientras tanto, Nginx puede responder 502 |
| `pdo_sqlite` deshabilitado | `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit` |
| Scheduler en Windows | Programador de tareas, cada 1 minuto |
| PowerShell 5.1 | No editar archivos con `Get-Content`/`Set-Content`: corrompen UTF-8 |

## Comprobar la instalación

| Comprobación | Resultado esperado |
|---|---|
| `php artisan test` | Todos en verde |
| `php artisan migrate:status` | Todas las migraciones en `Ran` |
| `GET /api/branding` | 200 |
| Login en el frontend | Entra con un usuario sembrado |

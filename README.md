# Fleet Analytics Wialon

Laravel MVP для аналитики автопарка и спецтехники на основе данных Wialon Hosting.

## Stack

- PHP 8.2+
- Laravel 11
- MySQL 8 или MariaDB 10.11
- Blade, Bootstrap 5, обычный JavaScript
- Chart.js, Leaflet, OpenStreetMap
- Laravel HTTP Client, Scheduler, cron
- Dockerfile для Coolify

React, Vue, Livewire, Alpine.js, Redis, очереди Laravel и микросервисы не используются.

## Local Run

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan fleet:seed-demo
php artisan serve
```

Demo users:

- `admin@example.com` / `password`
- `viewer@example.com` / `password`

Для локального SQLite можно временно указать:

```env
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database/database.sqlite
```

## Environment

Все секреты задаются только через `.env` или Coolify Environment Variables.

Обязательные production-переменные:

```env
APP_NAME="Fleet Analytics"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://your-domain.example
APP_TIMEZONE=Asia/Baku
APP_LOCALE=az

DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=fleet_analytics
DB_USERNAME=
DB_PASSWORD=

WIALON_BASE_URL=https://hst-api.wialon.com
WIALON_TOKEN=
WIALON_TIMEOUT=30
WIALON_CACHE_SESSION_MINUTES=30

RUN_MIGRATIONS=true
SEED_DEMO_DATA=false

ADMIN_NAME=Administrator
ADMIN_EMAIL=
ADMIN_PASSWORD=

TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true
```

`WIALON_TOKEN`, database password, `APP_KEY` and admin password must never be committed.

## Database

Core tables:

- `projects`
- `equipment_types`
- `equipments`
- `geofences`
- `equipment_daily_stats`
- `geofence_events`
- `settings`

Run migrations:

```bash
php artisan migrate
```

Seed demo data:

```bash
php artisan fleet:seed-demo
```

Production admin user is created by `DatabaseSeeder` when `ADMIN_EMAIL` and `ADMIN_PASSWORD` are set.

## Wialon Integration

Service: `app/Services/WialonService.php`

Implemented methods:

- `loginByToken()`
- `getSessionId()`
- `logout()`
- `getUnits()`
- `getUnit()`
- `getUnitLastPosition()`
- `getMessages()`
- `getGeofences()`
- `getReportData()`
- `calculateUnitDailyData()`

The Wialon session id is cached with Laravel cache. No Redis is required. The token is read from `WIALON_TOKEN` and is not displayed in the UI or written to logs.

Required Wialon permissions depend on the account setup, but the token must be able to:

- list units;
- read unit properties;
- read messages for units;
- read last position;
- read resources/geofences;
- execute reports if report templates are used.

If report-based calculations are used later, set these values on the Settings page:

- Wialon resource ID
- Wialon report template ID

## Sync Commands

Import units from Wialon:

```bash
php artisan fleet:sync-units
```

Calculate daily stats for yesterday:

```bash
php artisan fleet:sync-daily
```

Calculate one date:

```bash
php artisan fleet:sync-daily --date=2026-07-10
```

Scheduler is configured in `routes/console.php`:

- unit sync hourly;
- daily stats at `02:10`.

Cron/Coolify scheduled task:

```bash
php artisan schedule:run
```

Run it every minute.

## Healthcheck

Endpoint:

```text
GET /health
```

Success response:

```json
{
  "status": "ok",
  "app": "Fleet Analytics",
  "database": "ok"
}
```

If the database is unavailable, the endpoint returns HTTP 503 without exposing internal credentials.

## Coolify Deploy

1. Create a new Coolify Project.
2. Create a MySQL or MariaDB resource.
3. Create an Application from a private Git repository.
4. Give Coolify access through GitHub App, deploy key, or Git integration.
5. Select Dockerfile build pack.
6. Dockerfile path: `Dockerfile`.
7. Application port: `8080`.
8. Add the domain.
9. Add all Environment Variables from the Environment section.
10. Set `APP_KEY` using `php artisan key:generate --show` locally or generate a secure Laravel key.
11. Set `/health` as the healthcheck path.
12. Deploy.
13. Check the first deployment logs for migrations.
14. Add scheduled task: `php artisan schedule:run`.
15. Run scheduled task every minute.
16. Verify HTTPS through Coolify reverse proxy.
17. Verify Wialon connection from Settings by running unit sync.

The application container includes PHP-FPM + Nginx. The database is not inside the application Docker image and should be managed as a separate Coolify resource.

## Docker

Build:

```bash
docker build -t fleet-analytics-wialon .
```

Run example:

```bash
docker run --rm -p 8080:8080 --env-file .env fleet-analytics-wialon
```

The entrypoint:

- creates Laravel storage directories;
- fixes permissions for `storage` and `bootstrap/cache`;
- clears old config/route/view cache;
- runs `php artisan migrate --force` when `RUN_MIGRATIONS=true`;
- runs seeders when `SEED_DEMO_DATA=true`;
- caches config/routes/views;
- starts PHP-FPM and Nginx.

No destructive migration or database reset command is executed by the container.

## Git

Repository name:

```text
fleet-analytics-wialon
```

Main branch:

```text
main
```

If a private remote is not already available:

```bash
git init
git branch -M main
git add .
git commit -m "Initial Laravel application"
git remote add origin git@github.com:YOUR_ORG_OR_USER/fleet-analytics-wialon.git
git push -u origin main
```

Never push `.env`, Wialon tokens, database passwords, private keys, `vendor`, `node_modules`, logs, or Coolify secrets.

## Tests And Checks

The project targets Laravel 11 as requested. Composer 2.10+ may block Laravel 11 because of current security advisories. The project-level Composer config sets `policy.advisories.block=false` so the mandated Laravel 11 version can be installed. Re-enable Composer advisory blocking before upgrading to a patched framework branch.

```bash
composer validate
php artisan about
php artisan migrate:fresh --seed
php artisan route:list
php artisan config:cache
php artisan view:cache
php artisan test
```

Docker checks:

```bash
docker build -t fleet-analytics-wialon .
docker run --rm -p 8080:8080 --env-file .env fleet-analytics-wialon
curl http://127.0.0.1:8080/health
```

## Backup

Recommended production backups:

- daily MySQL/MariaDB dump;
- separate backup of uploaded/public storage if files are added later;
- backup before migrations;
- keep at least 7 daily and 4 weekly restore points;
- test restore on a non-production database.

## Known Limits

- MVP stores one current project assignment per equipment item.
- Heavy geospatial calculations are not implemented; Wialon data/geofence events should be preferred.
- Wialon report template IDs are stored in settings, but detailed report-table parsing should be adjusted to the final Wialon report template.
- Demo data is deterministic enough for dashboard testing but is not production telemetry.

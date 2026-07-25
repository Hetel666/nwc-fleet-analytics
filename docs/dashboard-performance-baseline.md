# Dashboard Performance Baseline

Branch: `codex/dashboard-performance-fixes`

Baseline date: 2026-07-24

## Local profiling status

The read-only profiler command is available:

```text
php artisan dashboard:profile [--project] [--date-from] [--date-to] [--widget] [--repetitions] [--json]
```

The default local environment uses SQLite with `CACHE_STORE=database`, but the local default SQLite database does not
contain the `cache` / `cache_locks` tables. The testing database migrations do create those tables.

For local read-only profiling, `CACHE_STORE=array` was used as a command-scoped override. This does not change
production configuration and avoids mutating the default local SQLite database.

```text
CACHE_STORE=array php artisan optimize:clear
CACHE_STORE=array php artisan dashboard:profile --help
```

No production database or production services were used.

## Representative validation used for this phase

The Average drilldown optimization baseline is characterized through deterministic tests:

- detail rows include generated missing unit-day rows;
- project, ownership, vehicle type, date and data-status filters are preserved;
- duplicate unit-day stats still use the latest row by id;
- pagination metadata is preserved;
- SQL `LIMIT/OFFSET` is present on the optimized Average detail query.

## Limitation

SQLite test validation is not a substitute for MariaDB production-like profiling. MariaDB EXPLAIN and browser/staging profiling remain required before claiming live performance resolution.

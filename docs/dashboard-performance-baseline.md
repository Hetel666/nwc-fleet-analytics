# Dashboard Performance Baseline

Branch: `codex/dashboard-performance-fixes`

Baseline date: 2026-07-24

## Local profiling status

The read-only profiler command is available:

```text
php artisan dashboard:profile [--project] [--date-from] [--date-to] [--widget] [--repetitions] [--json]
```

In the current local SQLite environment, direct profiler execution is blocked before Dashboard payload generation:

```text
SQLSTATE[HY000]: General error: 1 no such table: cache
Query: select * from "cache" where "key" in (laravel_cache_dashboard:data-version)
```

No production database or production services were used.

## Representative validation used for this phase

Because local profiling cannot run without the cache schema, the Average drilldown optimization baseline is characterized through deterministic tests:

- detail rows include generated missing unit-day rows;
- project, ownership, vehicle type, date and data-status filters are preserved;
- duplicate unit-day stats still use the latest row by id;
- pagination metadata is preserved;
- SQL `LIMIT/OFFSET` is present on the optimized Average detail query.

## Limitation

SQLite test validation is not a substitute for MariaDB production-like profiling. MariaDB EXPLAIN and browser/staging profiling remain required before claiming live performance resolution.

# Dashboard Performance Results

Branch: `codex/dashboard-performance-fixes`

Generated: 2026-07-24

## Implemented changes

| Scenario | Before | After | Result diff | Status |
| --- | --- | --- | --- | --- |
| Average Modal details | Full PHP unit-day collection before pagination | DB date-series x equipment query with count, order, limit, offset | 0 in regression tests | Implemented |
| Average summary | PHP loops over equipment x dates with loaded stats map | SQL latest-unit-day aggregation by equipment, PHP only classifies small type/ownership result | 0 in regression tests | Implemented |
| Efficiency summary | PHP materialized all daily rows | SQL aggregate over existing dailyRowsQuery | 0 in regression tests | Implemented |
| Excel worksheet XML | `$rows = []` held every XML row before `implode` | Worksheet row XML appended incrementally | 0 in XLSX contract tests | Implemented |
| Excel formula injection | Formula-like text could be written as plain leading `= + - @` text | Formula-like text is prefixed with `'` | Security behavior improved | Implemented |
| Remaining widgets | Reviewed for obvious safe bottlenecks | Geofence pagination has deeper uniqueness semantics and needs separate characterization/EXPLAIN before change | N/A | Deferred |
| Large async exports | Existing UI/status flow is not sufficient for safe async implementation in this pass | Not changed | N/A | Deferred |
| Wialon extraction from DashboardService | Production-critical sync callers still use DashboardService orchestration | Not changed; requires dedicated ETL extraction design | N/A | Blocked |

## Local environment

| Item | Value |
| --- | --- |
| Laravel | 12.63.0 |
| PHP | 8.5.8 |
| Default app env | production |
| Default DB | sqlite |
| Default cache | database |
| Default queue | database |
| Default session | database |
| Local profiling override | `CACHE_STORE=array` |

The local `.env` does not define `APP_ENV`, `DB_CONNECTION`, `CACHE_STORE`, `CACHE_DRIVER`, `SESSION_DRIVER`, or
`QUEUE_CONNECTION`; Laravel falls back to config defaults. The local default SQLite database does not contain
`cache` / `cache_locks`, so `CACHE_STORE=array` was used only for local read-only validation commands.

## Measured local profiler samples

All samples are local SQLite read-only profiler runs with `CACHE_STORE=array`; no production DB or production services
were used.

### Widget samples, 2026-07-23

| Metric | Value |
| --- | ---: |
| Overview cold / warm duration | 52.75 ms / 3.59 ms |
| Overview queries cold / warm | 9 / 9 |
| Daily average dashboards cold / warm duration | 64.28 ms / 5.10 ms |
| Daily average dashboards queries | 2 |
| Efficiency NWC/ICARE base widget cold / warm duration | 66.67 ms / 1.43 ms |
| Top20 least cold / warm duration | 91.45 ms / 3.72 ms |
| Top20 most cold / warm duration | 59.29 ms / 4.35 ms |
| Geofence cold / warm duration | 71.64 ms / 6.95 ms |
| Peak memory | 32 MB |

### Full Dashboard samples

| Period | Cold duration | Warm duration | Query count cold / warm | DB time cold | Peak memory |
| --- | ---: | ---: | ---: | ---: | ---: |
| 1 day | 170.41 ms | 1.05 ms | 35 / 0 | 22.82 ms | 32 MB |
| 7 days | 58.57 ms | 0.55 ms | 35 / 0 | 8.27 ms | 32 MB |
| 30 days | 71.07 ms | 0.49 ms | 35 / 0 | 8.00 ms | 32 MB |
| 90 days | 114.29 ms | 1.00 ms | 35 / 0 | 17.53 ms | 32 MB |
| 365 days | 119.87 ms | 0.42 ms | 35 / 0 | 17.82 ms | 34 MB |

## Validation

| Check | Result |
| --- | --- |
| Branch | `codex/dashboard-performance-fixes` |
| HEAD | `6e631498b95987dc6424da615ce587a529f4fad3` before this report update |
| `git diff --check` | Passed |
| `CACHE_STORE=array php artisan optimize:clear` | Passed |
| `php artisan dashboard:profile --help` | Passed |
| `php artisan migrate:fresh --env=testing` | Passed |
| `php artisan migrate:status --env=testing` | All migrations ran |
| `php artisan test` | 640 assertions, 0 failures, 138 warnings from existing `.env` file warnings |
| `php vendor/bin/pint --test --dirty` | Passed |
| `php vendor/bin/pint --test` | Failed on pre-existing unrelated style issues |
| `git diff --check` | Passed |
| `php artisan route:list` | 59 routes listed |
| `CACHE_STORE=array php artisan schedule:list` | Scheduler listed |
| `php artisan dashboard:profile --help` | Passed |

## MariaDB and EXPLAIN status

MariaDB validation was not completed in this local environment:

- Docker is not installed.
- `mysql` / `mariadb` CLI clients are not installed.
- PHP has `pdo_sqlite` and `sqlite3`, but no `pdo_mysql`.

Because there is no local/disposable MariaDB runtime, no MariaDB `EXPLAIN` output was collected and no index migration
was added. This is intentionally left as a required staging/MariaDB validation step rather than changing SQL or indexes
based on SQLite-only evidence.

## Cache concurrency status

The cache lock path is implemented and local warm-cache behavior was verified with `CACHE_STORE=array`:

- first full Dashboard build performed 35 queries;
- repeated identical full Dashboard reads used cached payloads with 0 queries.

Production-like Redis/database lock concurrency was not validated locally because no Redis/MariaDB runtime is available.

## Excel memory status

The XLSX writer now appends worksheet XML incrementally instead of holding a complete `$rows = []` worksheet array before
`implode`. Regression tests verify XLSX generation and formula-like text escaping. Large production-size export profiling
still requires staging data.

## Limitations

- `php artisan optimize:clear` against the default local SQLite/database-cache setup can fail because `database/database.sqlite` has no `cache` / `cache_locks` tables. Command-scoped `CACHE_STORE=array` passes and testing migrations create the cache schema successfully.
- MariaDB/MySQL tests and EXPLAIN validation were not completed because this machine has no Docker, no MySQL/MariaDB client, and no PHP `pdo_mysql`.
- Browser/staging profiling was not completed. The open production URL was not used for profiling because production load testing/deployment was not authorized.
- Production deployment was not performed.

## Final local status

`LOCAL FIXES COMPLETE`; `MARIADB VALIDATION REQUIRED`; `STAGING/BROWSER VALIDATION REQUIRED`.

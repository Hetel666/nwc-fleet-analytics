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

## Measured local profiler sample

`php artisan dashboard:profile --widget=overview --date-from=2026-07-23 --date-to=2026-07-23 --json`

| Metric | Value |
| --- | ---: |
| Duration | 42.67 ms |
| Query count | 9 |
| DB time | 3.8 ms |
| Peak memory | 32 MB |
| Result size | 0.45 KB |

## Validation

| Check | Result |
| --- | --- |
| `php artisan migrate:fresh --env=testing` | Passed |
| `php artisan migrate:status --env=testing` | All migrations ran |
| `php artisan test` | 640 assertions, 0 failures |
| `php vendor/bin/pint --test --dirty` | Passed |
| `php vendor/bin/pint --test` | Failed on pre-existing unrelated style issues |
| `git diff --check` | Passed |
| `php artisan route:list` | 59 routes listed |
| `php artisan schedule:list` | Scheduler listed |
| `php artisan dashboard:profile --help` | Passed |

## Limitations

- `php artisan optimize:clear` fails in the default local SQLite environment because `database/database.sqlite` has no `cache` table. Testing migrations create the cache table successfully.
- MariaDB/MySQL EXPLAIN validation was not completed in this local run.
- Browser/staging profiling was not completed in this local run.
- Production deployment was not performed.

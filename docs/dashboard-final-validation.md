# Dashboard Final Validation

Date: 2026-07-24

## Scope

Final local validation of the stacked Dashboard work:

1. Dashboard performance optimization.
2. Wialon ETL extraction from the Dashboard read path.

No production deployment, push, merge, production database connection, or real production Wialon synchronization was performed.

## Git State

Current branch: `codex/dashboard-wialon-etl-extraction`

Base commit: `3df86aab747473cd4712eb027b4ff98bbf97b71f`

Initial HEAD: `5d7eb1863bf25473fd18f8124dfc5fe94a847322`

Final HEAD: documentation commit `Document final Dashboard validation results` on `codex/dashboard-wialon-etl-extraction`.

Branch graph:

```text
codex/dashboard-performance-fixes
  3df86aab Document Dashboard performance validation results
      \
       44d479f3 Extract Wialon report sync from Dashboard read path
       5d7eb186 Document Dashboard Wialon dependency map
       <current> Document final Dashboard validation results
       codex/dashboard-wialon-etl-extraction
```

Ancestry:

- `3df86aab747473cd4712eb027b4ff98bbf97b71f` is an ancestor of `5d7eb1863bf25473fd18f8124dfc5fe94a847322`.
- Delta from base: `0 behind / 2 ahead`.

Tracked working tree: clean.

Allowed untracked files left untouched:

- `design-preview/average-type-metrics.html`
- `design-preview/efficiency-modal-filters.html`
- `docs/dashboard-performance-audit.md`

## Environment

Laravel: `12.63.0`

PHP: `8.5.8`

Application timezone: `Asia/Baku`

Local drivers from `php artisan about`:

- Database: `sqlite`
- Cache: `database`
- Session: `database`
- Queue: `database`

PDO drivers:

- `sqlite`

Local tooling availability:

- Docker CLI: unavailable.
- mysql CLI: unavailable.
- mariadb CLI: unavailable.
- redis-cli: unavailable.
- `pdo_mysql`: unavailable.

## Local SQLite Validation

Commands run:

```text
CACHE_STORE=array php artisan optimize:clear
php artisan migrate:fresh --env=testing
php artisan migrate:status --env=testing
php artisan test
php artisan test --filter=Dashboard
php artisan test --filter=Sync
php artisan test --filter=Historical
php artisan test --filter=Backfill
php artisan test --filter=WialonReportStatsSyncTest
php artisan test --filter=HistoricalRecalculationTest
php artisan fleet:backfill-statistics --from=2026-01-01 --to=2026-01-01 --all-projects --dry-run --env=testing
php vendor/bin/pint --test --dirty
git diff --check
php artisan route:list
CACHE_STORE=array php artisan schedule:list
php artisan list
```

Results:

- Testing migrations: passed, all migrations ran.
- Full test suite: passed with `700 assertions`, `0 failures`, `138 warnings`.
- Dashboard filter: passed with `340 assertions`, `0 failures`, `55 warnings`.
- Sync filter: passed with `93 assertions`, `0 failures`, `19 warnings`.
- Historical filter: passed with `13 assertions`, `0 failures`, `5 warnings`.
- Backfill filter: no tests found.
- `WialonReportStatsSyncTest`: passed with `5 assertions`, `0 failures`, `1 warning`.
- `HistoricalRecalculationTest`: passed with `13 assertions`, `0 failures`, `5 warnings`.
- Backfill dry-run: passed; no active project Wialon groups in the empty testing database.
- Dirty Pint: passed.
- `git diff --check`: passed.
- `route:list`: passed, `59` routes.
- `schedule:list`: passed.
- `artisan list`: passed.

The repeated warnings are local environment warnings around `file_get_contents(...)` paths containing Cyrillic characters. They did not fail assertions.

## Full Pint

`php vendor/bin/pint --test` fails on existing repository-wide style debt.

The branch-changed files pass targeted Pint:

```text
app/Console/Commands/BackfillFleetStatistics.php
app/Console/Commands/SyncWialonReportStats.php
app/Jobs/RunHistoricalRecalculationTaskJob.php
app/Services/DashboardService.php
app/Services/WialonReportStatsSyncService.php
docs/dashboard-wialon-etl-extraction.md
docs/dashboard-wialon-dependency-map.md
tests/Unit/DashboardReadPathArchitectureTest.php
```

Repository-wide style debt was not fixed because it is unrelated to this validation.

## Architecture Validation

Validated read path files:

- `app/Services/DashboardService.php`
- `app/Services/DashboardFleetDrilldownService.php`
- `app/Services/DashboardDailyAverageService.php`
- `app/Services/FleetEfficiencyService.php`
- `app/Services/TopWorkingUnitsService.php`
- `app/Services/GeofenceViolationService.php`
- Dashboard controllers and export controllers.

Architecture test:

- `DashboardReadPathArchitectureTest`: passed, `12` datasets, `60 assertions`.

Static search found no Dashboard read-path usage of:

- `WialonService`
- `getReportTablesRows`
- `executeReport`
- `findReportTemplateIdByName`
- live Wialon fallback
- direct sync command invocation
- direct Wialon client construction

Runtime no-Wialon coverage:

- `DashboardAccessTest` covers Dashboard open, cache miss with Wialon outage, modal endpoint, and Excel export without Wialon calls.
- These tests passed in the full and Dashboard-filtered suites.

Result:

```text
DASHBOARD READ PATH IS FULLY LOCAL
```

## Sync / ETL Validation

Production sync callers verified by command discovery, help output, tests, and dependency map:

- `fleet:sync-report-stats`
- `fleet:backfill-statistics`
- `RunHistoricalRecalculationTaskJob`
- `fleet:auto-sync`
- `fleet:sync-units`
- `fleet:sync-geofences`
- `fleet:sync-engine-hours-report`
- `fleet:sync-shift-daily-stats`
- `fleet:backfill-shift-stats`
- `fleet:plan-shift-sync`
- `fleet:run-shift-sync`
- `fleet:retry-shift-sync`
- `fleet:sync-geozon-api`
- legacy `fleet:sync-daily`

The extracted report stats callers now resolve `WialonReportStatsSyncService`:

- `SyncWialonReportStats`
- `BackfillFleetStatistics`
- `RunHistoricalRecalculationTaskJob`

Remaining Wialon dependencies are in sync/ETL commands, Wialon report services, Wialon parsers, and test fakes. This is expected.

## Legacy `fleet:sync-daily`

`fleet:sync-daily` is a standalone legacy sync path that:

- injects `WialonService`;
- iterates active dashboard-visible equipment;
- calls `calculateUnitDailyData`;
- writes `equipment_daily_stats`;
- writes `daily_unit_aggregates`;
- may update `last_position_json`.

Scheduler does not directly run `fleet:sync-daily`.

`fleet:auto-sync` daily processing calls:

- `fleet:sync-report-stats`
- `fleet:aggregate-daily`

Current assessment:

- `fleet:sync-daily` is a sync/ETL path, not a Dashboard read path.
- It was not removed or migrated in this validation.
- It can overlap table ownership with report-based stats if run manually for the same date/equipment.

Risk:

- P1: legacy sync ownership should be explicitly decided before production operators continue using `fleet:sync-daily`.

## Business Reconciliation

Local deterministic tests cover:

- Dashboard local data access without live Wialon.
- Dashboard modal and Excel without Wialon calls.
- Average formulas, duplicate unit-day handling, missing units, ownership/project filters.
- Efficiency daytime/overtime independence, missing data, category boundaries, filters, pagination.
- Top20 SQL order, limit, null handling, ties, filters.
- Geofence dashboard/modal/export selection consistency.
- Wialon report stats import into `equipment_daily_stats` and `daily_unit_aggregates`.
- Shift sync idempotency and failure/retry behavior.

Observed business result diff:

```text
0 detected in local deterministic test suite
```

MariaDB/staging business reconciliation remains required.

## Local Performance Profiling

Read-only command:

```text
php artisan dashboard:profile --repetitions=3 --json
```

Local SQLite profile summary:

| Period | Cold duration ms | Warm duration ms | Cold queries | Warm queries | Cold DB ms | Peak MB | Result KB |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 day | 209.82 | 1.36-11.43 | 42 | 2 | 67.74 | 32 | 10.63 |
| 7 days | 193.36 | 2.05-2.48 | 42 | 2 | 57.53 | 32 | 10.84 |
| 30 days | 202.63 | 1.43-2.46 | 42 | 2 | 49.89 | 32 | 11.70 |
| 90 days | 258.93 | 2.63-3.52 | 42 | 2 | 89.40 | 32 | 13.87 |
| 365 days | 275.86 | 3.31-3.68 | 42 | 2 | 74.06 | 34 | 23.84 |

The earlier 1-day cold anomaly was not reproduced here as a performance regression. Cold timings vary with PHP/bootstrap/filesystem/cache initialization. Warm cache is consistently low.

## MariaDB / EXPLAIN / Index Validation

MariaDB validation was not performed in this environment because:

- Docker CLI is unavailable.
- mysql/mariadb CLI is unavailable.
- `pdo_mysql` is unavailable.
- No project disposable Compose environment is available locally, only `deploy/docker-compose.production.yml`.

Status:

```text
MARIADB VALIDATION REQUIRED
```

EXPLAIN and index decisions:

- MariaDB EXPLAIN was not performed.
- No indexes were added.
- No indexes were rejected based on MariaDB plans because no MariaDB plan could be collected.

## Cache Concurrency

Local cache driver is database-backed SQLite; production-like Redis or MariaDB cache locking could not be validated because Redis/MariaDB are unavailable.

Validated locally:

- Dashboard warm cache hits use 2 queries.
- `DashboardPerformanceProfileTest` includes cache hit behavior.
- `WialonReportStatsSyncService` increments `dashboard:data-version` after successful transaction and import.

Status:

```text
PRODUCTION-LIKE CACHE CONCURRENCY VALIDATION REQUIRED
```

## Excel Memory

Local automated tests passed:

- `DashboardExportXlsxTest`: XLSX download and formula injection escaping.
- `DashboardDrilldownTest`: drilldown export uses same filters.
- `ForeignGeofenceMonitoringTest`: dashboard/modal/export count consistency.

Manual large export memory profiling was not performed because no representative MariaDB dataset is available locally.

Status:

```text
LARGE EXPORT MEMORY VALIDATION REQUIRED ON STAGING
```

## Staging / Browser Validation

Not performed.

Reason:

- The available browser context points to production URL.
- The validation instructions allow staging only and explicitly forbid production validation.
- No staging URL or staging credentials were provided.

Status:

```text
STAGING/BROWSER VALIDATION REQUIRED
```

## Security

No credentials, tokens, or production URLs were added to this document.

No route/API contract changes were made in the validated branches.

## Risk Classification

P0:

- None found in local validation.

P1:

- MariaDB validation is still required before merge.
- Staging/browser validation is still required before merge.
- Production-like cache concurrency validation is still required.
- Legacy `fleet:sync-daily` table ownership should be decided to avoid accidental operator-triggered overlap with report-based stats.

P2:

- Repository-wide Pint style debt remains outside branch-changed files.
- Local Windows path warnings with Cyrillic path segments remain in tests.
- Large export memory validation requires a representative staging/MariaDB dataset.

## Merge Recommendation

Current status:

```text
LOCAL FIXES COMPLETE
MARIADB VALIDATION REQUIRED
STAGING/BROWSER VALIDATION REQUIRED
```

Recommended merge order:

1. Review and merge `codex/dashboard-performance-fixes`.
2. Re-check ancestry/base for `codex/dashboard-wialon-etl-extraction`.
3. Review and merge `codex/dashboard-wialon-etl-extraction`.
4. Do not cherry-pick ETL commits without preserving the performance base.
5. Do not merge the ETL branch before the performance branch.

Do not proceed to merge until MariaDB and staging/browser validation are complete.

## Deployment Plan

Do not deploy from this validation.

Recommended deployment checklist:

1. Backup database and uploaded/runtime artifacts.
2. Confirm rollback release is available.
3. Deploy code after merge review.
4. Run additive migrations if any exist.
5. Clear and rebuild Laravel caches.
6. Restart queue workers.
7. Verify scheduler.
8. Run controlled sync smoke test.
9. Verify local records.
10. Verify Dashboard.
11. Verify Modal / Drilldown.
12. Verify Excel.
13. Check application logs.
14. Check failed jobs.
15. Check cache invalidation and `dashboard:data-version`.
16. Confirm Dashboard HTTP requests do not call Wialon.

## Rollback Plan

1. Stop queue workers before rollback if ETL jobs are active.
2. Identify queued jobs introduced by the new release.
3. Safely complete or remove incompatible queued jobs.
4. Roll back application release.
5. Roll back reversible migrations if any were introduced after this validation.
6. Clear Laravel caches.
7. Restart queue workers.
8. Verify scheduler compatibility.
9. Verify Dashboard reads local data.
10. Re-run controlled sync if local aggregates need refresh.

No destructive database changes were introduced by this validation.

## Final Status

```text
LOCAL FIXES COMPLETE
DASHBOARD READ PATH IS FULLY LOCAL
WIALON SYNC IS ISOLATED IN ETL LAYER
MARIADB VALIDATION REQUIRED
STAGING/BROWSER VALIDATION REQUIRED
PRODUCTION DEPLOYMENT NOT AUTHORIZED
```

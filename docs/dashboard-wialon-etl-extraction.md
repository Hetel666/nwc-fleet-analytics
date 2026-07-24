# Dashboard Wialon ETL Extraction

Branch: `codex/dashboard-wialon-etl-extraction`

Base branch: `codex/dashboard-performance-fixes`

Date: 2026-07-24

## Goal

Dashboard read requests must use only local database data. Wialon access remains available only in ETL/sync paths.

No Dashboard API, UI contract, formulas, migrations, or production deployment were changed.

## Dependency Map

| Method / class | Current caller | Purpose | Path | Safe to move | Replacement |
| --- | --- | --- | --- | --- | --- |
| `DashboardService` | Dashboard controllers, exports, profile/diagnostic commands | Build Dashboard payloads from local tables | Read | N/A | Kept as local read service |
| `DashboardFleetDrilldownService` | Modal / drilldown / drilldown export | Local detail rows | Read | N/A | No Wialon dependency |
| `DashboardDailyAverageService` | Average widgets/modal/Excel | Local average aggregation | Read | N/A | No Wialon dependency |
| `FleetEfficiencyService` | Efficiency widgets/modal/Excel | Local shift stats aggregation | Read | N/A | No Wialon dependency |
| `TopWorkingUnitsService` | Top20 widgets/export | Local `engine_hours_report_unit_days` reads | Read | N/A | No Wialon dependency |
| `GeofenceViolationService` | Geofence widget/modal/Excel/diagnostic | Local `unit_foreign_geofence_intervals` reads | Read | N/A | No Wialon dependency |
| `DashboardService::syncDailyEngineHoursReport` | `fleet:sync-report-stats`, backfill, historical job | Fetch Wialon daily engine report and store local stats | Sync | Yes | Moved to `WialonReportStatsSyncService::syncDailyEngineHoursReport` |
| `DashboardService::syncDailyOwnershipEngineHoursReport` | `fleet:sync-report-stats --root-groups` | Fetch root ownership Wialon report and store local stats | Sync | Yes | Moved to `WialonReportStatsSyncService::syncDailyOwnershipEngineHoursReport` |
| `WialonReportStatsSyncService` | Sync command, backfill command, historical recalculation job | Engine hours / mileage ETL from Wialon into local tables | Sync | N/A | New ETL service |
| `WialonEngineHoursReportService` | `fleet:sync-engine-hours-report` | Top20 report ETL | Sync | Already isolated | Left unchanged |
| `WialonShiftReportService` / `WialonShiftSyncService` | shift sync planner/runner/commands | Shift efficiency ETL | Sync | Already isolated | Left unchanged |
| `WialonGeozonReportService` | `fleet:sync-geozon-api` | Geofence interval ETL | Sync | Already isolated | Left unchanged |
| `WialonService` | Wialon-specific sync services/commands | Wialon API client | Sync | N/A | Removed from Dashboard read path |

## Architecture Before

```text
Dashboard controllers
  -> DashboardService
      -> local DB reads
      -> disabled live Wialon fallback code
      -> WialonService injection
      -> syncDailyEngineHoursReport used by sync/backfill/historical
```

## Architecture After

```text
Dashboard / Modal / Drilldown / Excel
  -> DashboardService and read services
      -> local DB only

fleet:sync-report-stats / backfill / historical recalculation
  -> WialonReportStatsSyncService
      -> WialonService
      -> equipment_daily_stats
      -> daily_unit_aggregates
      -> dashboard:data-version cache bump
```

## Production Callers

The production-critical callers were preserved and redirected:

- `fleet:sync-report-stats` now injects `WialonReportStatsSyncService`.
- `fleet:backfill-statistics` now injects `WialonReportStatsSyncService`.
- `RunHistoricalRecalculationTaskJob` now injects `WialonReportStatsSyncService`.

Scheduler, queue, backfill, and historical recalculation keep the same behavior because method contracts and return
payloads were preserved.

## Dashboard Isolation

Static read-path scan found no direct occurrences of:

- `WialonService`
- `getReportTablesRows`
- `executeReport`
- `findReportTemplateIdByName`

in:

- Dashboard controllers;
- `DashboardService`;
- modal/drilldown services;
- average service;
- efficiency service;
- Top20 service;
- geofence violation service;
- Blade / JS resources.

An architecture regression test now enforces this for the main Dashboard read path files.

## What Remains

Wialon references remain only in sync/ETL classes and Wialon-specific commands:

- `WialonReportStatsSyncService`
- `WialonEngineHoursReportService`
- `WialonShiftReportService`
- `WialonShiftSyncService`
- `WialonGeozonReportService`
- `WialonSessionManager`
- `WialonService`
- `SyncWialonUnits`
- `SyncWialonGeofences`
- `SyncDailyStats`

These are sync paths, not Dashboard HTTP read paths.

## Validation

- `DashboardReadPathArchitectureTest` passes.
- Dashboard access/modal/export tests pass.
- Wialon report stats sync test passes.
- Historical recalculation tests pass.
- Feature and Unit suites pass when run separately.

## Remaining Risks

- Full `php artisan test` timed out once in the local shell, but separate `tests/Unit` and `tests/Feature` runs passed.
- MariaDB/staging validation is still required before merge if this branch is promoted.
- No production deployment was performed.

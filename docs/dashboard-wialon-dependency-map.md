# Dashboard Wialon Dependency Map

Branch: `codex/dashboard-wialon-etl-extraction`

Base commit: `3df86aab747473cd4712eb027b4ff98bbf97b71f`

Date: 2026-07-24

## Scope

This map documents the Wialon-related dependencies that were checked while separating Dashboard read requests from Wialon sync and ETL orchestration.

Target architecture:

```text
Dashboard / Modal / Drilldown / Excel
  -> local read services
  -> local database

Artisan / Scheduler / Queue
  -> ETL and sync services
  -> Wialon API
  -> local database
```

## Dependency Table

| Class / method | Caller | Entry point | Purpose | Read/Sync | Production critical | Target service | Migration risk |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `DashboardService` | Dashboard controllers, exports, profile/diagnostic commands | `/dashboard`, Dashboard API/export routes | Builds Dashboard payload from local tables | Read | Yes | Stays in read path | Medium: central read service, must not regain Wialon dependency |
| `DashboardFleetDrilldownService` | `DashboardDrilldownController` | Modal / drilldown endpoints | Builds local detail rows | Read | Yes | Stays in read path | Low |
| `DashboardDailyAverageService` | `DashboardService`, drilldown/export flows | Average engine hours and mileage widgets | Builds local average aggregates | Read | Yes | Stays in read path | Medium: formula-sensitive |
| `FleetEfficiencyService` | `DashboardService`, modal/export flows | Efficiency widgets and details | Builds local shift efficiency summaries | Read | Yes | Stays in read path | Medium: status formula-sensitive |
| `TopWorkingUnitsService` | `DashboardService`, export flows | Top 20 widgets | Reads local engine-hours report unit-days | Read | Yes | Stays in read path | Low |
| `GeofenceViolationService` | `DashboardService`, geofence modal/export/diagnostic | Geofence violation widget and details | Reads local foreign-geofence intervals | Read | Yes | Stays in read path | Medium: interval filtering-sensitive |
| `DashboardService::syncDailyEngineHoursReport` | `fleet:sync-report-stats`, `fleet:backfill-statistics`, `RunHistoricalRecalculationTaskJob` before extraction | Artisan, backfill, queue | Fetches project group engine hours/mileage report and persists local stats | Sync | Yes | `WialonReportStatsSyncService::syncDailyEngineHoursReport` | High before extraction because sync lived in read service |
| `DashboardService::syncDailyOwnershipEngineHoursReport` | `fleet:sync-report-stats --root-groups` before extraction | Artisan | Fetches root ownership engine hours/mileage report and persists local stats | Sync | Yes | `WialonReportStatsSyncService::syncDailyOwnershipEngineHoursReport` | High before extraction because sync lived in read service |
| `WialonReportStatsSyncService` | `SyncWialonReportStats`, `BackfillFleetStatistics`, `RunHistoricalRecalculationTaskJob` | `fleet:sync-report-stats`, `fleet:backfill-statistics`, historical queue | Engine-hours/mileage ETL from Wialon report to `equipment_daily_stats` and `daily_unit_aggregates` | Sync | Yes | New ETL service | Medium: preserves old method contracts and result payloads |
| `WialonService::getReportTablesRows` | `WialonReportStatsSyncService` and test fakes | Sync service only | Executes Wialon report and returns rows | Sync | Yes | Remains in Wialon client | Medium: remote availability and Wialon response shape |
| `WialonEngineHoursReportService` | `SyncEngineHoursReport`, `EngineHoursTop20SyncService` | `fleet:sync-engine-hours-report` | Imports Top20 source rows from Wialon Engine hours report | Sync | Yes | Already isolated sync service | Medium |
| `WialonShiftReportService` | `SyncShiftDailyStats`, `BackfillShiftStats`, `DiagnoseShiftReport`, `WialonShiftSyncService` | Shift sync commands and diagnostics | Fetches shift report rows from Wialon | Sync | Yes | Already isolated sync service | Medium |
| `WialonShiftSyncService` | `PlanShiftSync`, `RunShiftSync`, `RetryShiftSync`, `ShiftSyncStatus` | Shift queue/checkpoint commands | Orchestrates shift report checkpoint sync | Sync | Yes | Already isolated sync service | Medium |
| `WialonGeozonReportService` | `SyncGeozonApi`, `DiagnoseGeozonApi` | Geozon sync and diagnostic commands | Fetches geofence interval report rows from Wialon | Sync | Yes | Already isolated sync service | Medium |
| `WialonService` | Wialon-specific services and sync commands | Wialon sync commands | Low-level Wialon API client | Sync | Yes | Remains outside Dashboard read path | Medium: central remote client |
| `SyncWialonUnits` | Manual/scheduled unit sync | `fleet:sync-units` | Imports units and project group classification from Wialon | Sync | Yes | Unchanged sync command | Medium |
| `SyncWialonGeofences` | Manual/scheduled geofence sync | `fleet:sync-geofences` | Imports configured project geofences from Wialon | Sync | Yes | Unchanged sync command | Medium |
| `SyncDailyStats` | Legacy daily message sync | `fleet:sync-daily` | Calculates local daily stats from Wialon messages | Sync | Existing legacy caller | Unchanged | High: legacy path, not part of Dashboard read path |
| `HistoricalRecalculationService` | Admin historical recalculation controller/jobs | Admin historical recalculations | Plans and tracks recalculation tasks | Sync orchestration metadata | Yes | Unchanged, delegates fetch job to ETL service | Medium |
| `RunHistoricalRecalculationTaskJob::handle` | Queue worker | Historical recalculation fetch task | Runs per-day project ownership fetch through ETL service | Sync | Yes | Uses `WialonReportStatsSyncService` | Medium |
| `routes/console.php` | Laravel scheduler | Scheduler | Schedules geozon sync | Sync | Yes | Unchanged | Low |
| `AutoSyncFleetData` | Manual/scheduled auto sync wrapper | `fleet:auto-sync` | Runs configured sync commands | Sync | Yes | Unchanged caller; `fleet:sync-report-stats` now resolves ETL service | Medium |
| `config/fleet.php` | Wialon services | Config | Wialon credentials, report IDs, timeouts | Sync config | Yes | Remains config-only | Low |
| `config/dashboard_analytics.php` | Admin dashboard sources UI | Admin analytics metadata | Describes dashboard sources and bindings | Metadata | Yes | Unchanged read-only metadata | Low |

## DashboardService Method Classification

| Method group | Classification | Decision |
| --- | --- | --- |
| Dashboard overview, ownership, type distribution, average metrics, work-hour categories, top working units, geofence violations | B: local-read | Kept in `DashboardService` or delegated local read services |
| `syncDailyEngineHoursReport` | A: sync orchestration | Moved to `WialonReportStatsSyncService` |
| `syncDailyOwnershipEngineHoursReport` | A: sync orchestration | Moved to `WialonReportStatsSyncService` |
| Wialon report fetch helpers and report row parsers used only by those sync methods | A: sync orchestration | Moved to `WialonReportStatsSyncService` |
| Live Dashboard Wialon fallback state and `shouldUseLiveWialonReports` | D: disabled/obsolete read-path remote fallback | Removed from `DashboardService` |

## Production Entry Points

| Entry point | Current dependency | Behavior preserved |
| --- | --- | --- |
| `fleet:sync-report-stats` | `WialonReportStatsSyncService` | Same command signature, same per-day/project/ownership loop, same skipped/synced/failed summary |
| `fleet:sync-report-stats --root-groups` | `WialonReportStatsSyncService` | Same root ownership path |
| `fleet:backfill-statistics` | `WialonReportStatsSyncService` | Same signature, lock, retry, dry-run, resume and backfill item behavior |
| `RunHistoricalRecalculationTaskJob` | `WialonReportStatsSyncService` | Same queue payload and task state transitions |
| `fleet:auto-sync` | Runs `fleet:sync-report-stats` | Indirectly uses the extracted ETL service |
| Shift/geozon/unit sync commands | Wialon-specific services | Unchanged |

## Verified Absences In Read Path

Architecture tests and static scans verify that the main Dashboard read path does not import or call:

- `WialonService`
- `getReportTablesRows`
- `executeReport`
- `findReportTemplateIdByName`

Covered read-path files:

- `app/Services/DashboardService.php`
- `app/Services/DashboardFleetDrilldownService.php`
- `app/Services/DashboardDailyAverageService.php`
- `app/Services/FleetEfficiencyService.php`
- `app/Services/TopWorkingUnitsService.php`
- `app/Services/GeofenceViolationService.php`
- Dashboard controllers and export controllers

## Notes

- Wialon identifiers such as `wialon_unit_id`, `wialon_group_id`, and `wialon_geofence_id` still appear in local read code as stored data fields. They are not remote API calls.
- `WialonGroupClassificationService` is a local classification/mapping service that reads project group mappings; it is not a remote report client.
- No migration was needed for this extraction.
- No production deployment, push, or merge was performed.

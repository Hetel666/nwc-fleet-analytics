<?php

use App\Http\Controllers\EfficiencyDashboardController;
use App\Http\Controllers\GeofenceViolationsDashboardController;
use App\Http\Controllers\MonthlyEfficiencyDashboardController;
use App\Models\HistoricalRecalculation;
use App\Services\DashboardDailyAverageService;
use App\Services\DashboardService;
use App\Services\EfficiencyDashboardService;
use App\Services\EfficiencyRecalculationHandler;
use App\Services\GeofenceViolationReportImporter;
use App\Services\GeofenceViolationsDashboardService;
use App\Services\GeofenceViolationService;
use App\Services\MonthlyEfficiencyDashboardService;
use App\Services\WialonGeozonReportService;
use App\Services\WialonReportStatsSyncService;

return [
    /*
    |--------------------------------------------------------------------------
    | Dashboard module registry
    |--------------------------------------------------------------------------
    |
    | This is a read-only contract map. It documents the current production
    | dashboard modules and their data boundaries; it must not execute syncs or
    | delete data. Future isolated rebuild tools should use these contracts
    | instead of hard-coded table and command lists.
    |
    */
    'modules' => [
        'overview' => [
            'title' => 'Ümumi baxış',
            'tab' => 'overview',
            'dashboard_section' => null,
            'source_report' => 'No direct Wialon report on HTTP read path',
            'collector_command' => 'dashboard-reports:sync-daily -> efficiency stage',
            'manual_command' => 'fleet:sync-report-stats / fleet:sync-geozon-api as needed',
            'auto_schedule' => '00:00 Asia/Baku dashboard-reports:sync-daily',
            'result_tables' => [
                'equipments',
                'projects',
                'equipment_types',
                'equipment_daily_stats',
                'daily_unit_aggregates',
                'engine_hours_report_unit_days',
                'unit_foreign_geofence_intervals',
                'geofence_violation_report_rows',
            ],
            'shared_result_tables' => [
                'equipment_daily_stats',
                'daily_unit_aggregates',
                'engine_hours_report_unit_days',
            ],
            'read_service' => DashboardService::class,
            'collector_service' => WialonReportStatsSyncService::class,
            'api_endpoints' => [
                'GET /dashboard?tab=overview',
                'GET /dashboard/drilldown/units',
                'GET /dashboard/ownership/export',
            ],
            'frontend_widgets' => [
                'ownership-share',
                'equipment-types-nwc',
                'equipment-types-icare',
                'project-comparison',
                'average-engine-hours',
                'average-mileage',
                'utilization-trend',
            ],
            'safe_resync_scope' => [
                'status' => 'shared',
                'keys' => ['date_from', 'date_to', 'project_id', 'ownership_type'],
                'risk' => 'Shares daily Engine hours tables with average.',
            ],
            'dry_run_tables' => [
                ['table' => 'equipment_daily_stats', 'date_column' => 'stat_date', 'project_column' => 'project_id'],
                ['table' => 'daily_unit_aggregates', 'date_column' => 'date', 'project_column' => 'project_id'],
                ['table' => 'engine_hours_report_unit_days', 'date_column' => 'stat_date', 'project_column' => 'project_id'],
                ['table' => 'unit_foreign_geofence_intervals', 'date_from_column' => 'report_from', 'date_to_column' => 'report_to', 'project_column' => 'foreign_project_id'],
                ['table' => 'geofence_violation_report_rows', 'date_from_column' => 'report_period_from', 'date_to_column' => 'report_period_to', 'project_column' => 'project_id'],
            ],
            'writes_shared_tables' => true,
            'failure_isolation' => 'Low: shared daily tables are reused by several overview widgets.',
        ],
        'efficiency' => [
            'title' => 'Effektivlik 24 saat',
            'tab' => 'efficiency',
            'dashboard_section' => HistoricalRecalculation::SECTION_EFFICIENCY,
            'source_report' => 'Qrup date report Engine hours (api)',
            'collector_command' => 'fleet:queue-efficiency-sync',
            'manual_command' => 'fleet:queue-efficiency-sync --from=YYYY-MM-DD --to=YYYY-MM-DD --force',
            'auto_schedule' => '00:00 Asia/Baku dashboard-reports:sync-daily',
            'result_tables' => [
                'efficiency_daily_facts',
                'efficiency_sync_runs',
                'efficiency_sync_tasks',
                'efficiency_unmatched_report_rows',
                'equipment_daily_stats',
                'daily_unit_aggregates',
                'engine_hours_report_unit_days',
                'wialon_report_sync_items',
            ],
            'shared_result_tables' => [
                'equipment_daily_stats',
                'daily_unit_aggregates',
                'engine_hours_report_unit_days',
                'wialon_report_sync_items',
            ],
            'read_service' => EfficiencyDashboardService::class,
            'collector_service' => EfficiencyRecalculationHandler::class,
            'controller' => EfficiencyDashboardController::class,
            'api_endpoints' => [
                'GET /api/dashboard/efficiency/summary',
                'GET /api/dashboard/efficiency/projects',
                'GET /api/dashboard/efficiency/units',
                'GET /api/dashboard/efficiency/export',
            ],
            'frontend_widgets' => [
                'project-work-categories-nwc',
                'project-work-categories-icare',
            ],
            'safe_resync_scope' => [
                'status' => 'shared',
                'keys' => ['business_date', 'project_id', 'ownership_type'],
                'risk' => 'Force rebuild deletes/recreates efficiency facts and refreshes shared daily tables.',
            ],
            'dry_run_tables' => [
                ['table' => 'efficiency_daily_facts', 'date_column' => 'business_date', 'project_column' => 'project_id'],
                ['table' => 'efficiency_sync_runs', 'date_from_column' => 'date_from', 'date_to_column' => 'date_to', 'project_column' => ''],
                ['table' => 'efficiency_sync_tasks', 'date_column' => 'business_date', 'project_column' => 'project_id'],
                ['table' => 'efficiency_unmatched_report_rows', 'date_column' => 'business_date', 'project_column' => 'project_id'],
                ['table' => 'equipment_daily_stats', 'date_column' => 'stat_date', 'project_column' => 'project_id'],
                ['table' => 'daily_unit_aggregates', 'date_column' => 'date', 'project_column' => 'project_id'],
                ['table' => 'engine_hours_report_unit_days', 'date_column' => 'stat_date', 'project_column' => 'project_id'],
                ['table' => 'wialon_report_sync_items', 'date_column' => 'report_date', 'project_column' => 'project_id'],
            ],
            'writes_shared_tables' => true,
            'failure_isolation' => 'Medium: module has its own facts, but also refreshes shared daily aggregates.',
        ],
        'monthly_efficiency' => [
            'title' => 'Aylıq effektivlik',
            'tab' => 'efficiency',
            'dashboard_section' => HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
            'source_report' => 'Report for Aylıq effektivlik',
            'collector_command' => 'monthly-efficiency:sync-objects',
            'manual_command' => 'monthly-efficiency:sync-objects --from=YYYY-MM-DD --to=YYYY-MM-DD --force',
            'auto_schedule' => 'Not part of dashboard-reports:sync-daily yet',
            'result_tables' => [
                'equipment_daily_stats',
                'efficiency_daily_facts',
                'monthly_efficiency_unit_geofence_facts',
                'wialon_geofences',
            ],
            'shared_result_tables' => [
                'equipment_daily_stats',
                'efficiency_daily_facts',
            ],
            'read_service' => MonthlyEfficiencyDashboardService::class,
            'collector_service' => 'App\\Console\\Commands\\SyncMonthlyEfficiencyObjects',
            'controller' => MonthlyEfficiencyDashboardController::class,
            'api_endpoints' => [
                'GET /api/dashboard/monthly-efficiency/summary',
                'GET /api/dashboard/monthly-efficiency/projects',
                'GET /api/dashboard/monthly-efficiency/units',
                'GET /api/dashboard/monthly-efficiency/objects',
                'GET /api/dashboard/monthly-efficiency/object-geofences',
                'GET /api/dashboard/monthly-efficiency/object-geofence-days',
                'GET /api/dashboard/monthly-efficiency/export',
            ],
            'frontend_widgets' => [
                'monthly-efficiency-nwc',
                'monthly-efficiency-icare',
            ],
            'safe_resync_scope' => [
                'status' => 'partially_isolated',
                'keys' => ['stat_date', 'wialon_unit_id', 'segment_type', 'geofence_name', 'source_report_name'],
                'risk' => 'Object/geofence facts are isolated, but legacy monthly mode still reads shared daily facts.',
            ],
            'dry_run_tables' => [
                ['table' => 'monthly_efficiency_unit_geofence_facts', 'date_column' => 'stat_date', 'project_column' => ''],
                ['table' => 'equipment_daily_stats', 'date_column' => 'stat_date', 'project_column' => 'project_id', 'note' => 'Legacy monthly fallback reads this shared table.'],
                ['table' => 'efficiency_daily_facts', 'date_column' => 'business_date', 'project_column' => 'project_id', 'note' => 'Legacy monthly fallback reads this shared table.'],
            ],
            'writes_shared_tables' => false,
            'failure_isolation' => 'Medium: object facts are isolated; legacy fallback depends on shared daily facts.',
        ],
        'daily_averages' => [
            'title' => 'Orta göstəricilər',
            'tab' => 'overview',
            'dashboard_section' => HistoricalRecalculation::SECTION_DAILY_AVERAGES,
            'source_report' => 'Qrup date report Engine hours (api)',
            'collector_command' => 'dashboard-reports:sync-daily -> efficiency stage / daily_averages module on manual runs',
            'manual_command' => 'dashboard-reports:queue-sync --module=daily_averages',
            'auto_schedule' => 'No separate scheduled stage; refreshed by shared Engine hours sync',
            'result_tables' => [
                'equipment_daily_stats',
                'daily_unit_aggregates',
            ],
            'shared_result_tables' => [
                'equipment_daily_stats',
                'daily_unit_aggregates',
            ],
            'read_service' => DashboardDailyAverageService::class,
            'collector_service' => WialonReportStatsSyncService::class,
            'api_endpoints' => [
                'GET /dashboard',
                'GET /dashboard/drilldown/units',
                'GET /dashboard/export',
            ],
            'frontend_widgets' => [
                'average-engine-hours',
                'average-mileage',
            ],
            'safe_resync_scope' => [
                'status' => 'shared',
                'keys' => ['stat_date', 'project_id', 'ownership_type'],
                'risk' => 'Same shared tables feed overview metrics and monthly legacy mode.',
            ],
            'dry_run_tables' => [
                ['table' => 'equipment_daily_stats', 'date_column' => 'stat_date', 'project_column' => 'project_id'],
                ['table' => 'daily_unit_aggregates', 'date_column' => 'date', 'project_column' => 'project_id'],
            ],
            'writes_shared_tables' => true,
            'failure_isolation' => 'Low: depends on shared daily stats.',
        ],
        'geofence_transfers' => [
            'title' => 'Geofence Transferləri',
            'tab' => 'geozones',
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
            'source_report' => 'Geofence Transferləri api / geozon api',
            'collector_command' => 'fleet:sync-geozon-api',
            'manual_command' => 'dashboard-reports:queue-sync --module=geofence_outside',
            'auto_schedule' => '00:00 Asia/Baku dashboard-reports:sync-daily',
            'result_tables' => [
                'unit_foreign_geofence_intervals',
            ],
            'shared_result_tables' => [],
            'read_service' => GeofenceViolationService::class,
            'collector_service' => WialonGeozonReportService::class,
            'api_endpoints' => [
                'GET /dashboard?tab=geozones',
                'GET /dashboard/drilldown/units',
                'GET /dashboard/export',
            ],
            'frontend_widgets' => [
                'geofence-analysis',
            ],
            'safe_resync_scope' => [
                'status' => 'isolated',
                'keys' => ['report_from', 'report_to', 'project_id'],
                'risk' => 'Deletes must stay inside geofence transfer interval data only.',
            ],
            'dry_run_tables' => [
                ['table' => 'unit_foreign_geofence_intervals', 'date_from_column' => 'report_from', 'date_to_column' => 'report_to', 'project_column' => 'foreign_project_id'],
            ],
            'writes_shared_tables' => false,
            'failure_isolation' => 'High: own interval table; shared risk is Wialon report lock.',
        ],
        'geofence_violations' => [
            'title' => 'Geofence Pozuntuları',
            'tab' => 'geozones',
            'dashboard_section' => HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
            'source_report' => 'Geofence Pozuntuları api',
            'collector_command' => 'fleet:sync-geofence-violations-report',
            'manual_command' => 'dashboard-reports:queue-sync --module=geofence_violations',
            'auto_schedule' => '00:00 Asia/Baku dashboard-reports:sync-daily',
            'result_tables' => [
                'geofence_violation_report_rows',
                'geofence_violation_sync_items',
            ],
            'shared_result_tables' => [],
            'read_service' => GeofenceViolationsDashboardService::class,
            'collector_service' => GeofenceViolationReportImporter::class,
            'controller' => GeofenceViolationsDashboardController::class,
            'api_endpoints' => [
                'GET /geofence-violations',
                'GET /geofence-violations/export',
                'GET /dashboard/geofence-violations/drilldown',
            ],
            'frontend_widgets' => [
                'geofence-violations-report',
            ],
            'safe_resync_scope' => [
                'status' => 'isolated',
                'keys' => ['report_period_from', 'report_period_to', 'project_id'],
                'risk' => 'Chunk retries must not duplicate period_key rows.',
            ],
            'dry_run_tables' => [
                ['table' => 'geofence_violation_report_rows', 'date_from_column' => 'report_period_from', 'date_to_column' => 'report_period_to', 'project_column' => 'project_id'],
                ['table' => 'geofence_violation_sync_items', 'date_from_column' => 'report_period_from', 'date_to_column' => 'report_period_to', 'project_column' => 'project_id'],
            ],
            'writes_shared_tables' => false,
            'failure_isolation' => 'High: own normalized report rows and sync items.',
        ],
    ],
];

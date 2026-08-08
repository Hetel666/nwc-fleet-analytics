<?php

use App\Http\Controllers\DaytimeEfficiencyDashboardController;
use App\Http\Controllers\EfficiencyDashboardController;
use App\Http\Controllers\GeofenceViolationsDashboardController;
use App\Http\Controllers\MonthlyEfficiencyDashboardController;
use App\Http\Controllers\NightDayEfficiencyDashboardController;
use App\Http\Controllers\NighttimeEfficiencyDashboardController;
use App\Models\HistoricalRecalculation;
use App\Services\DashboardDailyAverageService;
use App\Services\DashboardService;
use App\Services\DaytimeEfficiencyDashboardService;
use App\Services\DaytimeEfficiencyRecalculationHandler;
use App\Services\EfficiencyDashboardService;
use App\Services\EfficiencyRecalculationHandler;
use App\Services\EngineHoursTop20SyncService;
use App\Services\GeofenceViolationReportImporter;
use App\Services\GeofenceViolationsDashboardService;
use App\Services\GeofenceViolationService;
use App\Services\MonthlyEfficiencyDashboardService;
use App\Services\NightDayEfficiencyDashboardService;
use App\Services\NightDayEfficiencyRecalculationHandler;
use App\Services\NighttimeEfficiencyDashboardService;
use App\Services\NighttimeEfficiencyRecalculationHandler;
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
            'manual_command' => 'fleet:sync-report-stats / fleet:sync-engine-hours-report / fleet:sync-geozon-api as needed',
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
                'GET /dashboard/top-working-units/export',
            ],
            'frontend_widgets' => [
                'ownership-share',
                'equipment-types-nwc',
                'equipment-types-icare',
                'project-comparison',
                'average-engine-hours',
                'average-mileage',
                'least-working',
                'most-working',
                'utilization-trend',
            ],
            'safe_resync_scope' => [
                'status' => 'shared',
                'keys' => ['date_from', 'date_to', 'project_id', 'ownership_type'],
                'risk' => 'Shares daily Engine hours tables with average and Top 20 widgets.',
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
            'source_report' => 'daily_stats / efficiency_daily_facts; object mode uses Report for Aylıq effektivlik (unit)',
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
        'daytime_efficiency' => [
            'title' => 'Gündüz növbəsi üzrə effektivlik',
            'tab' => 'efficiency',
            'dashboard_section' => HistoricalRecalculation::SECTION_DAYTIME_EFFICIENCY,
            'source_report' => 'day report Engine hours (api)',
            'collector_command' => 'dashboard-reports:sync-daily -> daytime_efficiency stage',
            'manual_command' => 'daytime-efficiency:sync-yesterday / dashboard-reports:queue-sync --module=daytime_efficiency',
            'auto_schedule' => '00:00 Asia/Baku dashboard-reports:sync-daily',
            'result_tables' => [
                'daytime_efficiency_daily_facts',
                'daytime_efficiency_sync_runs',
                'daytime_efficiency_sync_tasks',
                'daytime_efficiency_unmatched_report_rows',
            ],
            'shared_result_tables' => [],
            'read_service' => DaytimeEfficiencyDashboardService::class,
            'collector_service' => DaytimeEfficiencyRecalculationHandler::class,
            'controller' => DaytimeEfficiencyDashboardController::class,
            'api_endpoints' => [
                'GET /api/dashboard/daytime-efficiency/summary',
                'GET /api/dashboard/daytime-efficiency/projects',
                'GET /api/dashboard/daytime-efficiency/units',
                'GET /api/dashboard/daytime-efficiency/export',
            ],
            'frontend_widgets' => [
                'daytime-efficiency-nwc',
                'daytime-efficiency-icare',
            ],
            'safe_resync_scope' => [
                'status' => 'isolated',
                'keys' => ['business_date', 'project_id', 'wialon_unit_id'],
                'risk' => 'Own fact tables; shared risk is Wialon report session lock only.',
            ],
            'dry_run_tables' => [
                ['table' => 'daytime_efficiency_daily_facts', 'date_column' => 'business_date', 'project_column' => 'project_id'],
                ['table' => 'daytime_efficiency_sync_runs', 'date_from_column' => 'date_from', 'date_to_column' => 'date_to', 'project_column' => ''],
                ['table' => 'daytime_efficiency_sync_tasks', 'date_column' => 'business_date', 'project_column' => 'project_id'],
                ['table' => 'daytime_efficiency_unmatched_rows', 'date_column' => 'business_date', 'project_column' => 'project_id'],
            ],
            'writes_shared_tables' => false,
            'failure_isolation' => 'High: own facts and own sync run/task tables.',
        ],
        'nighttime_efficiency' => [
            'title' => 'Gecə növbəsi üzrə effektivlik',
            'tab' => 'efficiency',
            'dashboard_section' => HistoricalRecalculation::SECTION_NIGHTTIME_EFFICIENCY,
            'source_report' => 'night report Engine hours (api)',
            'collector_command' => 'nighttime-efficiency:sync-last-completed-shift',
            'manual_command' => 'nighttime-efficiency:sync-last-completed-shift --force / dashboard-reports:queue-sync --module=nighttime_efficiency',
            'auto_schedule' => '08:30 Asia/Baku nighttime-efficiency:sync-last-completed-shift',
            'result_tables' => [
                'nighttime_efficiency_daily_facts',
                'nighttime_efficiency_sync_runs',
                'nighttime_efficiency_sync_tasks',
                'nighttime_efficiency_unmatched_report_rows',
            ],
            'shared_result_tables' => [],
            'read_service' => NighttimeEfficiencyDashboardService::class,
            'collector_service' => NighttimeEfficiencyRecalculationHandler::class,
            'controller' => NighttimeEfficiencyDashboardController::class,
            'api_endpoints' => [
                'GET /api/dashboard/nighttime-efficiency/summary',
                'GET /api/dashboard/nighttime-efficiency/projects',
                'GET /api/dashboard/nighttime-efficiency/units',
                'GET /api/dashboard/nighttime-efficiency/export',
            ],
            'frontend_widgets' => [
                'nighttime-efficiency-nwc',
                'nighttime-efficiency-icare',
            ],
            'safe_resync_scope' => [
                'status' => 'isolated',
                'keys' => ['shift_date', 'project_id', 'wialon_unit_id'],
                'risk' => 'Own fact tables; must preserve 18:00-07:59 shift_date semantics.',
            ],
            'dry_run_tables' => [
                ['table' => 'nighttime_efficiency_daily_facts', 'date_column' => 'shift_date', 'project_column' => 'project_id'],
                ['table' => 'nighttime_efficiency_sync_runs', 'date_from_column' => 'date_from', 'date_to_column' => 'date_to', 'project_column' => ''],
                ['table' => 'nighttime_efficiency_sync_tasks', 'date_column' => 'shift_date', 'project_column' => 'project_id'],
                ['table' => 'nighttime_efficiency_unmatched_rows', 'date_column' => 'shift_date', 'project_column' => 'project_id'],
            ],
            'writes_shared_tables' => false,
            'failure_isolation' => 'High: own facts and own sync run/task tables.',
        ],
        'night_day_efficiency' => [
            'title' => 'Gün daxilində gecə effektivliyi',
            'tab' => 'efficiency',
            'dashboard_section' => HistoricalRecalculation::SECTION_NIGHT_DAY_EFFICIENCY,
            'source_report' => 'night day report Engine hours (api)',
            'collector_command' => 'dashboard-reports:sync-daily -> night_day_efficiency stage',
            'manual_command' => 'dashboard-reports:queue-sync --module=night_day_efficiency',
            'auto_schedule' => '00:00 Asia/Baku dashboard-reports:sync-daily',
            'result_tables' => [
                'night_day_efficiency_daily_facts',
                'night_day_efficiency_sync_runs',
                'night_day_efficiency_sync_tasks',
                'night_day_efficiency_unmatched_rows',
            ],
            'shared_result_tables' => [],
            'read_service' => NightDayEfficiencyDashboardService::class,
            'collector_service' => NightDayEfficiencyRecalculationHandler::class,
            'controller' => NightDayEfficiencyDashboardController::class,
            'api_endpoints' => [
                'GET /api/dashboard/night-day-efficiency/summary',
                'GET /api/dashboard/night-day-efficiency/projects',
                'GET /api/dashboard/night-day-efficiency/units',
                'GET /api/dashboard/night-day-efficiency/export',
            ],
            'frontend_widgets' => [
                'night-day-efficiency-nwc',
                'night-day-efficiency-icare',
            ],
            'safe_resync_scope' => [
                'status' => 'isolated',
                'keys' => ['business_date', 'project_id', 'wialon_unit_id'],
                'risk' => 'Own fact tables; must keep calendar-day night windows separate from shift nighttime.',
            ],
            'dry_run_tables' => [
                ['table' => 'night_day_efficiency_daily_facts', 'date_column' => 'business_date', 'project_column' => 'project_id'],
                ['table' => 'night_day_efficiency_sync_runs', 'date_from_column' => 'date_from', 'date_to_column' => 'date_to', 'project_column' => ''],
                ['table' => 'night_day_efficiency_sync_tasks', 'date_column' => 'business_date', 'project_column' => 'project_id'],
                ['table' => 'night_day_efficiency_unmatched_rows', 'date_column' => 'business_date', 'project_column' => 'project_id'],
            ],
            'writes_shared_tables' => false,
            'failure_isolation' => 'High: own facts and own sync run/task tables.',
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
        'top_working_units' => [
            'title' => 'Top 20',
            'tab' => 'overview',
            'dashboard_section' => HistoricalRecalculation::SECTION_TOP_WORKING_UNITS,
            'source_report' => 'Qrup date report Engine hours (api)',
            'collector_command' => 'fleet:sync-engine-hours-report',
            'manual_command' => 'dashboard-reports:queue-sync --module=top_working_units',
            'auto_schedule' => 'No separate scheduled stage; refreshed by shared Engine hours sync',
            'result_tables' => [
                'engine_hours_report_unit_days',
                'wialon_report_sync_items',
            ],
            'shared_result_tables' => [
                'engine_hours_report_unit_days',
                'wialon_report_sync_items',
            ],
            'read_service' => EngineHoursTop20SyncService::class,
            'collector_service' => EngineHoursTop20SyncService::class,
            'api_endpoints' => [
                'GET /dashboard/top-working-units/export',
                'GET /dashboard/export',
            ],
            'frontend_widgets' => [
                'least-working',
                'most-working',
            ],
            'safe_resync_scope' => [
                'status' => 'shared',
                'keys' => ['report_date', 'project_id', 'ownership_type'],
                'risk' => 'Shared report sync item status can affect export/readiness displays.',
            ],
            'dry_run_tables' => [
                ['table' => 'engine_hours_report_unit_days', 'date_column' => 'stat_date', 'project_column' => 'project_id'],
                ['table' => 'wialon_report_sync_items', 'date_column' => 'report_date', 'project_column' => 'project_id'],
            ],
            'writes_shared_tables' => true,
            'failure_isolation' => 'Medium: read model is shared with overview but narrower than equipment_daily_stats.',
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

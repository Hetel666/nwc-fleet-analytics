<?php

return [
    'wialon' => [
        'base_url' => env('WIALON_BASE_URL', 'https://hst-api.wialon.com'),
        'token' => env('WIALON_TOKEN'),
        'timeout' => (int) env('WIALON_TIMEOUT', 30),
        'session_cache_minutes' => (int) env('WIALON_CACHE_SESSION_MINUTES', 30),
        'report_status_attempts' => (int) env('WIALON_REPORT_STATUS_ATTEMPTS', 300),
        'report_status_delay_ms' => (int) env('WIALON_REPORT_STATUS_DELAY_MS', 1000),
        'report_rows_attempts' => (int) env('WIALON_REPORT_ROWS_ATTEMPTS', 6),
        'report_rows_delay_ms' => (int) env('WIALON_REPORT_ROWS_DELAY_MS', 1000),
        'engine_hours_report_resource_id' => (int) env('WIALON_ENGINE_HOURS_REPORT_RESOURCE_ID', 601701680),
        'engine_hours_report_template_id' => (int) env('WIALON_ENGINE_HOURS_REPORT_TEMPLATE_ID', 9),
        'engine_hours_report_cache_minutes' => (int) env('WIALON_ENGINE_HOURS_REPORT_CACHE_MINUTES', 30),
        'engine_hours_report_timeout' => (int) env('WIALON_ENGINE_HOURS_REPORT_TIMEOUT', 15),
        'daily_engine_hours_report_timeout' => (int) env('WIALON_DAILY_ENGINE_HOURS_REPORT_TIMEOUT', 30),
        'report_stats_sync_timeout' => (int) env('WIALON_REPORT_STATS_SYNC_TIMEOUT', 90),
        'nwc_group_id' => env('WIALON_NWC_GROUP_ID'),
        'icare_group_id' => env('WIALON_ICARE_GROUP_ID'),
        'actual_work_report_resource_id' => (int) env('WIALON_ACTUAL_WORK_REPORT_RESOURCE_ID', env('WIALON_ENGINE_HOURS_REPORT_RESOURCE_ID', 601701680)),
        'actual_work_report_template_id' => (int) env('WIALON_ACTUAL_WORK_REPORT_TEMPLATE_ID', 0),
        'actual_work_report_template_name' => env('WIALON_ACTUAL_WORK_REPORT_TEMPLATE_NAME', 'Qrup report DNN,day 24 saat (api)'),
        'actual_work_report_cache_minutes' => (int) env('WIALON_ACTUAL_WORK_REPORT_CACHE_MINUTES', 30),
        'actual_work_report_timeout' => (int) env('WIALON_ACTUAL_WORK_REPORT_TIMEOUT', 10),
        'geofence_outside_report_resource_id' => (int) env('WIALON_GEOFENCE_OUTSIDE_REPORT_RESOURCE_ID', env('WIALON_ENGINE_HOURS_REPORT_RESOURCE_ID', 601701680)),
        'geofence_outside_report_template_id' => (int) env('WIALON_GEOFENCE_OUTSIDE_REPORT_TEMPLATE_ID', 0),
        'geofence_outside_report_template_name' => env('WIALON_GEOFENCE_OUTSIDE_REPORT_TEMPLATE_NAME', 'Dashboard geofence outside (Api)'),
        'geofence_outside_report_cache_minutes' => (int) env('WIALON_GEOFENCE_OUTSIDE_REPORT_CACHE_MINUTES', 30),
        'geofence_outside_report_timeout' => (int) env('WIALON_GEOFENCE_OUTSIDE_REPORT_TIMEOUT', 10),
        'daily_report_fallback_max_days' => (int) env('WIALON_DAILY_REPORT_FALLBACK_MAX_DAYS', 3),
        'daily_report_fallback_max_reports' => (int) env('WIALON_DAILY_REPORT_FALLBACK_MAX_REPORTS', 14),
    ],

    'dashboard' => [
        'cache_minutes' => (int) env('DASHBOARD_CACHE_MINUTES', 10),
        'export_queue_threshold_rows' => (int) env('DASHBOARD_EXPORT_QUEUE_THRESHOLD_ROWS', 5000),
        'slow_generation_ms' => (int) env('DASHBOARD_SLOW_GENERATION_MS', 5000),
    ],

    'geofence' => [
        'min_exit_minutes' => (int) env('GEOFENCE_MIN_EXIT_MINUTES', 3),
    ],

    'demo' => [
        'seed' => (bool) env('SEED_DEMO_DATA', false),
    ],
];

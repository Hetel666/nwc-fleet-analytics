<?php

return [
    'report_name' => env('GEOFENCE_VIOLATIONS_REPORT_NAME', 'Geofence Pozuntuları api'),
    'resource_id' => (int) env('GEOFENCE_VIOLATIONS_REPORT_RESOURCE_ID', 601701680),
    'template_id' => (int) env('GEOFENCE_VIOLATIONS_REPORT_TEMPLATE_ID', 22),
    'interval_flags' => (int) env('GEOFENCE_VIOLATIONS_REPORT_INTERVAL_FLAGS', 0),
    'chunk_size' => (int) env('GEOFENCE_VIOLATIONS_REPORT_CHUNK_SIZE', 500),
    'timeout' => (int) env('GEOFENCE_VIOLATIONS_REPORT_TIMEOUT', 60),
    'minimum_duration_seconds' => 10_800,
    'duration_tolerance_seconds' => 5,
    'active_end_tolerance_seconds' => 300,
    'default_period_days' => 7,
    'max_dashboard_period_days' => (int) env('GEOFENCE_VIOLATIONS_MAX_DASHBOARD_PERIOD_DAYS', 366),
    'max_report_period_days' => (int) env('GEOFENCE_VIOLATIONS_MAX_REPORT_PERIOD_DAYS', 31),
    'summary_cache_seconds' => (int) env('GEOFENCE_VIOLATIONS_SUMMARY_CACHE_SECONDS', 300),
    'checkpoint_retention_days' => (int) env('GEOFENCE_VIOLATIONS_CHECKPOINT_RETENTION_DAYS', 90),
    'source_payload_retention_days' => (int) env('GEOFENCE_VIOLATIONS_SOURCE_PAYLOAD_RETENTION_DAYS', 90),
    'per_page' => 25,
    'allowed_equipment_types' => [
        'Bulldozer',
        'Excavator',
        'Loader',
        'Backhoe Loader',
        'Road Grader',
        'Road Roller',
        'Dump Truck',
    ],
];

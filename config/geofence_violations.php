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

<?php

return [
    'report_name' => env('GEOFENCE_VIOLATIONS_REPORT_NAME', 'Geofence Pozuntuları api'),
    'minimum_duration_seconds' => 10_800,
    'duration_tolerance_seconds' => 5,
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

<?php

return [
    'layout_setting_key' => 'dashboard.layout.default',

    'widgets' => [
        'ownership-share' => [
            'default_order' => 10,
            'default_width' => 4,
            'column_class' => 'col-12 col-lg-6 col-xxl-4',
            'active' => true,
        ],
        'equipment-types-nwc' => [
            'default_order' => 20,
            'default_width' => 4,
            'column_class' => 'col-12 col-lg-6 col-xxl-4',
            'active' => true,
        ],
        'equipment-types-icare' => [
            'default_order' => 30,
            'default_width' => 4,
            'column_class' => 'col-12 col-lg-6 col-xxl-4',
            'active' => true,
        ],
        'project-work-categories-nwc' => [
            'default_order' => 40,
            'default_width' => 6,
            'column_class' => 'col-12 col-xl-6',
            'active' => true,
        ],
        'project-work-categories-icare' => [
            'default_order' => 50,
            'default_width' => 6,
            'column_class' => 'col-12 col-xl-6',
            'active' => true,
        ],
        'project-averages' => [
            'default_order' => 60,
            'default_width' => 4,
            'column_class' => 'col-12 col-xl-4',
            'active' => true,
        ],
        'least-working' => [
            'default_order' => 70,
            'default_width' => 6,
            'column_class' => 'col-12 col-xl-6',
            'active' => true,
        ],
        'most-working' => [
            'default_order' => 80,
            'default_width' => 6,
            'column_class' => 'col-12 col-xl-6',
            'active' => true,
        ],
        'geofence-analysis' => [
            'default_order' => 90,
            'default_width' => 7,
            'column_class' => 'col-12 col-xl-7',
            'active' => true,
        ],
        'utilization-trend' => [
            'default_order' => 100,
            'default_width' => 5,
            'column_class' => 'col-12 col-xl-5',
            'active' => true,
        ],
        'project-comparison' => [
            'default_order' => 110,
            'default_width' => 12,
            'column_class' => 'col-12',
            'active' => true,
        ],
    ],

    'sync' => [
        'enabled' => (bool) env('DASHBOARD_SYNC_ENABLED', false),
        'daily_time' => env('DASHBOARD_SYNC_DAILY_TIME', '02:30'),
        'lock_seconds' => (int) env('DASHBOARD_SYNC_LOCK_SECONDS', 300),
        'overlap_minutes' => (int) env('DASHBOARD_SYNC_OVERLAP_MINUTES', 120),
    ],
];

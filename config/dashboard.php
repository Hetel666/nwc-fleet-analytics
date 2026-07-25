<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Dashboard widgets
    |--------------------------------------------------------------------------
    |
    | This registry is the single server-side source for widget keys, default
    | order, responsive width and activation state. Saved layouts reference
    | only these keys; Blade markup is never accepted from the client.
    |
    */
    'layout_setting_key' => 'dashboard.layout.default',

    'widgets' => [
        'ownership-share' => [
            'label_key' => 'app.ownership_share',
            'default_order' => 10,
            'default_width' => 4,
            'column_class' => 'col-12 col-lg-6 col-xxl-4',
            'active' => true,
        ],
        'equipment-types-nwc' => [
            'label_key' => 'app.equipment_type_distribution',
            'default_order' => 20,
            'default_width' => 4,
            'column_class' => 'col-12 col-lg-6 col-xxl-4',
            'active' => true,
        ],
        'equipment-types-icare' => [
            'label_key' => 'app.equipment_type_distribution',
            'default_order' => 30,
            'default_width' => 4,
            'column_class' => 'col-12 col-lg-6 col-xxl-4',
            'active' => true,
        ],
        'project-comparison' => [
            'label_key' => 'app.work_hours_by_ownership',
            'default_order' => 40,
            'default_width' => 12,
            'column_class' => 'col-12',
            'active' => true,
        ],
        'project-work-categories-nwc' => [
            'label_key' => 'app.work_hours_by_ownership',
            'default_order' => 50,
            'default_width' => 6,
            'column_class' => 'col-12 col-xl-6',
            'active' => true,
        ],
        'project-work-categories-icare' => [
            'label_key' => 'app.work_hours_by_ownership',
            'default_order' => 60,
            'default_width' => 6,
            'column_class' => 'col-12 col-xl-6',
            'active' => true,
        ],
        'average-engine-hours' => [
            'label_key' => 'app.avg_engine_hours',
            'default_order' => 70,
            'default_width' => 6,
            'column_class' => 'col-12 col-xl-6',
            'active' => true,
        ],
        'average-mileage' => [
            'label_key' => 'app.avg_mileage',
            'default_order' => 80,
            'default_width' => 6,
            'column_class' => 'col-12 col-xl-6',
            'active' => true,
        ],
        'least-working' => [
            'label_key' => 'app.least_working',
            'default_order' => 90,
            'default_width' => 6,
            'column_class' => 'col-12 col-xl-6',
            'active' => true,
        ],
        'most-working' => [
            'label_key' => 'app.most_working',
            'default_order' => 100,
            'default_width' => 6,
            'column_class' => 'col-12 col-xl-6',
            'active' => true,
        ],
        'geofence-analysis' => [
            'label_key' => 'app.geofence_analysis',
            'default_order' => 110,
            'default_width' => 7,
            'column_class' => 'col-12 col-xl-7',
            'active' => true,
        ],
        'utilization-trend' => [
            'label_key' => 'app.utilization_trend',
            'default_order' => 120,
            'default_width' => 5,
            'column_class' => 'col-12 col-xl-5',
            'active' => true,
        ],
    ],
];

<?php

return [
    'timezone' => 'Asia/Baku',

    'day_shift' => [
        'start' => '08:00:00',
        'end' => '18:00:59',
    ],

    'overtime' => [
        ['start' => '00:00:00', 'end' => '07:59:59'],
        ['start' => '18:01:00', 'end' => '23:59:59'],
    ],

    'allowed_vehicle_types' => [
        'dump-truck',
        'excavator',
        'road-grader',
        'loader',
        'backhoe-loader',
        'road-roller',
    ],

    'average_engine_hours_vehicle_types' => [
        'excavator',
        'road_grader',
        'loader',
        'backhoe_loader',
        'road_roller',
    ],

    'average_mileage_vehicle_types' => [
        'dump_truck',
    ],

    'top_working_vehicle_types' => [
        'dump_truck',
        'excavator',
        'road_grader',
        'loader',
        'backhoe_loader',
        'road_roller',
    ],

    'type_aliases' => [
        'bakhoe-loader' => 'backhoe-loader',
        'bakhoe_loader' => 'backhoe_loader',
        'backhoe-loader' => 'backhoe-loader',
        'backhoe_loader' => 'backhoe_loader',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard business thresholds
    |--------------------------------------------------------------------------
    |
    | The label "7-10 saat" is a business category. Per the customer rule,
    | five daytime hours already belongs to that category for these two
    | efficiency widgets only.
    |
    */
    'daytime_status_rules' => [
        'less_than_1' => ['min_hours' => 0, 'max_hours' => 1, 'max_inclusive' => false],
        'from_1_to_7' => ['min_hours' => 1, 'max_hours' => 5, 'max_inclusive' => false],
        'from_7_to_10' => ['min_hours' => 5, 'max_hours' => 10, 'max_inclusive' => true],
    ],
];

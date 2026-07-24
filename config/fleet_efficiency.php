<?php

use App\Support\FleetVehicleType;

return [
    'timezone' => 'Asia/Baku',

    'day_shift' => [
        'start' => '08:00:00',
        'end' => '17:59:59',
    ],

    'overtime' => [
        ['start' => '00:00:00', 'end' => '07:59:59'],
        ['start' => '18:00:00', 'end' => '23:59:59'],
    ],

    'allowed_vehicle_types' => FleetVehicleType::slugs(FleetVehicleType::ANALYTICS_TYPES),

    'efficiency_vehicle_types' => FleetVehicleType::slugs(FleetVehicleType::EFFICIENCY_TYPES),

    'average_engine_hours_vehicle_types' => FleetVehicleType::AVERAGE_ENGINE_HOURS_TYPES,

    'average_mileage_vehicle_types' => FleetVehicleType::AVERAGE_MILEAGE_TYPES,

    'top_working_vehicle_types' => FleetVehicleType::TOP_WORKING_TYPES,

    'type_aliases' => FleetVehicleType::configAliases(),

    /*
    |--------------------------------------------------------------------------
    | Dashboard business thresholds
    |--------------------------------------------------------------------------
    |
    | These two efficiency widgets are based only on daytime shift hours.
    | Overtime is counted separately and never changes the daytime status.
    |
    */
    'daytime_status_rules' => [
        'less_than_1_hour' => ['min_hours' => 0, 'max_hours' => 1, 'max_inclusive' => false],
        'less_than_7_hours' => ['min_hours' => 1, 'max_hours' => 7, 'max_inclusive' => false],
        'between_7_and_10_hours' => ['min_hours' => 7, 'max_hours' => 10, 'max_inclusive' => true],
        'over_10_hours' => ['min_hours' => 10, 'max_hours' => null, 'max_inclusive' => false],
    ],
];

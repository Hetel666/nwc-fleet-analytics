<?php

use App\Support\FleetVehicleType;

return [
    'enabled' => (bool) env('DAYTIME_EFFICIENCY_ENABLED', true),
    'timezone' => 'Asia/Baku',
    'report_source' => 'daytime',
    'report_template_name' => env('WIALON_SHIFT_DAYTIME_REPORT_TEMPLATE_NAME', 'Qrup report daytime (api)'),
    'allowed_equipment_types' => FleetVehicleType::slugs(FleetVehicleType::EFFICIENCY_TYPES),
    'categories' => [
        'between_0_and_1' => ['color' => '#2874e8'],
        'between_1_and_7' => ['color' => '#ff7a12'],
        'between_7_and_10' => ['color' => '#20b65a'],
        'no_data_or_not_working' => ['color' => '#98a9bd'],
        'over_10' => ['color' => '#e5484d'],
    ],
    'page_size' => 50,
    'max_page_size' => 200,
];

<?php

use App\Support\EfficiencyStatus;

return [
    'cache_key' => 'dashboard:global-display-configuration',

    'dashboards' => [
        'overview_kpi' => [
            'title_az' => 'Umumi KPI',
            'section' => 'overview',
            'default_order' => 5,
            'layout_widget' => null,
        ],
        'ownership_share' => [
            'title_az' => 'Mensubiyyet payi',
            'section' => 'overview',
            'default_order' => 10,
            'layout_widget' => 'ownership-share',
            'export_blocks' => ['ownership-share'],
        ],
        'nwc_vehicle_type_share' => [
            'title_az' => 'NWC texnika novleri',
            'section' => 'overview',
            'default_order' => 20,
            'layout_widget' => 'equipment-types-nwc',
            'export_blocks' => ['equipment-types-nwc'],
        ],
        'rental_vehicle_type_share' => [
            'title_az' => 'Icare texnika novleri',
            'section' => 'overview',
            'default_order' => 30,
            'layout_widget' => 'equipment-types-icare',
            'export_blocks' => ['equipment-types-icare'],
        ],
        'project_ownership_share' => [
            'title_az' => 'Layihələr üzrə mensubiyyet',
            'section' => 'overview',
            'default_order' => 40,
            'layout_widget' => 'project-comparison',
            'export_blocks' => ['project-comparison'],
        ],
        'efficiency_general_nwc' => [
            'title_az' => 'Effektivlik umumi: NWC',
            'section' => 'efficiency',
            'default_order' => 110,
            'layout_widget' => 'project-work-categories-nwc',
            'export_blocks' => ['actual-work-hours-nwc'],
        ],
        'efficiency_general_rental' => [
            'title_az' => 'Effektivlik umumi: Icare',
            'section' => 'efficiency',
            'default_order' => 111,
            'layout_widget' => 'project-work-categories-icare',
            'export_blocks' => ['actual-work-hours-icare'],
        ],
        'efficiency_daytime_nwc' => [
            'title_az' => 'Effektivlik gunduz: NWC',
            'section' => 'efficiency',
            'default_order' => 210,
            'layout_widget' => null,
        ],
        'efficiency_daytime_rental' => [
            'title_az' => 'Effektivlik gunduz: Icare',
            'section' => 'efficiency',
            'default_order' => 211,
            'layout_widget' => null,
        ],
        'efficiency_nighttime_nwc' => [
            'title_az' => 'Effektivlik gece: NWC',
            'section' => 'efficiency',
            'default_order' => 310,
            'layout_widget' => null,
        ],
        'efficiency_nighttime_rental' => [
            'title_az' => 'Effektivlik gece: Icare',
            'section' => 'efficiency',
            'default_order' => 311,
            'layout_widget' => null,
        ],
        'average_engine_hours' => [
            'title_az' => 'Orta Engine hours',
            'section' => 'efficiency',
            'default_order' => 410,
            'layout_widget' => 'average-engine-hours',
            'export_blocks' => ['average-engine-hours'],
        ],
        'average_mileage' => [
            'title_az' => 'Orta yurush',
            'section' => 'efficiency',
            'default_order' => 411,
            'layout_widget' => 'average-mileage',
            'export_blocks' => ['average-mileage'],
        ],
        'top_20_low' => [
            'title_az' => 'Top 20 az isleyen',
            'section' => 'efficiency',
            'default_order' => 510,
            'layout_widget' => 'least-working',
            'export_blocks' => ['least-working'],
        ],
        'top_20_high' => [
            'title_az' => 'Top 20 cox isleyen',
            'section' => 'efficiency',
            'default_order' => 511,
            'layout_widget' => 'most-working',
            'export_blocks' => ['most-working'],
        ],
        'geofence_violations' => [
            'title_az' => 'Geofence pozuntulari',
            'section' => 'geozones',
            'default_order' => 610,
            'layout_widget' => 'geofence-violations-report',
            'export_blocks' => ['geofence-violations-report'],
        ],
        'geofence_transfers' => [
            'title_az' => 'Geofence transferleri',
            'section' => 'geozones',
            'default_order' => 600,
            'layout_widget' => 'geofence-analysis',
            'export_blocks' => ['geofence-analysis'],
        ],
    ],

    'status_types' => [
        'general_efficiency' => [
            'title_az' => 'Umumi effektivlik',
        ],
        'daytime_efficiency' => [
            'title_az' => 'Gunduz effektivliyi',
        ],
        'nighttime_efficiency' => [
            'title_az' => 'Gece effektivliyi',
        ],
    ],

    'statuses' => [
        EfficiencyStatus::ZERO_TO_ONE => [
            'title_az' => '0 - 1 saat arasi',
            'default_order' => 10,
        ],
        EfficiencyStatus::ONE_TO_SEVEN => [
            'title_az' => '1 - 7 saat arasi',
            'default_order' => 20,
        ],
        EfficiencyStatus::SEVEN_TO_TEN => [
            'title_az' => '7 - 10 saat arasi',
            'default_order' => 30,
        ],
        EfficiencyStatus::OVER_TEN => [
            'title_az' => '10 saatdan artiq',
            'default_order' => 40,
        ],
        EfficiencyStatus::NO_DATA => [
            'title_az' => 'Melumat yoxdur',
            'default_order' => 50,
        ],
    ],
];

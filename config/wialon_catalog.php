<?php

return [
    'connection' => env('WIALON_CATALOG_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'queue' => env('WIALON_CATALOG_QUEUE', 'wialon-catalog'),
    'lock_seconds' => (int) env('WIALON_CATALOG_LOCK_SECONDS', 1800),
    'sync_timeout' => (int) env('WIALON_CATALOG_SYNC_TIMEOUT', 900),
    'auto_sync_enabled' => filter_var(env('WIALON_CATALOG_AUTO_SYNC', false), FILTER_VALIDATE_BOOL),
    'auto_sync_time' => env('WIALON_CATALOG_AUTO_SYNC_TIME', '23:00'),

    'sections' => [
        'resources',
        'unit_groups',
        'units',
        'geofence_groups',
        'geofences',
        'report_templates',
    ],

    'used_report_templates' => [
        'Qrup report Engine hours (api)' => [
            'Effektivlik',
            'Top 20',
            'Orta motosaat',
            'Orta yürüş',
        ],
        'day report Engine hours (api)' => [
            'Effektivlik gündüz',
        ],
        'night report Engine hours (api)' => [
            'Effektivlik gecə',
        ],
        'night day report Engine hours (api)' => [
            'Gün daxilində gecə effektivliyi',
        ],
        'Geofence Pozuntuları api' => [
            'Geofence Pozuntuları',
        ],
        'Geofence Transferləri api' => [
            'Geozonadan çıxma halları',
        ],
    ],
];

<?php

return [
    'wialon' => [
        'base_url' => env('WIALON_BASE_URL', 'https://hst-api.wialon.com'),
        'token' => env('WIALON_TOKEN'),
        'timeout' => (int) env('WIALON_TIMEOUT', 30),
        'session_cache_minutes' => (int) env('WIALON_CACHE_SESSION_MINUTES', 30),
    ],

    'geofence' => [
        'min_exit_minutes' => (int) env('GEOFENCE_MIN_EXIT_MINUTES', 3),
    ],

    'demo' => [
        'seed' => (bool) env('SEED_DEMO_DATA', false),
    ],
];

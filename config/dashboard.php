<?php

return [
    'sync' => [
        'enabled' => (bool) env('DASHBOARD_SYNC_ENABLED', false),
        'daily_time' => env('DASHBOARD_SYNC_DAILY_TIME', '02:30'),
        'lock_seconds' => (int) env('DASHBOARD_SYNC_LOCK_SECONDS', 300),
        'overlap_minutes' => (int) env('DASHBOARD_SYNC_OVERLAP_MINUTES', 120),
    ],
];

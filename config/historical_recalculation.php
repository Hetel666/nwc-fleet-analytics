<?php

return [
    'timezone' => env('FLEET_TIMEZONE', 'Asia/Baku'),
    'max_range_days' => (int) env('HISTORICAL_RECALCULATION_MAX_RANGE_DAYS', 365),
    'connection' => env('HISTORICAL_RECALCULATION_CONNECTION', 'database'),
    'queue' => env('HISTORICAL_RECALCULATION_QUEUE', 'historical-recalculations'),
    'tries' => (int) env('HISTORICAL_RECALCULATION_TRIES', 8),
    'timeout' => (int) env('HISTORICAL_RECALCULATION_TIMEOUT', 900),
    'lock_seconds' => (int) env('HISTORICAL_RECALCULATION_LOCK_SECONDS', 7200),
    'report_task_delay_seconds' => (int) env('HISTORICAL_RECALCULATION_REPORT_TASK_DELAY_SECONDS', 5),
    'stale_running_task_seconds' => (int) env('HISTORICAL_RECALCULATION_STALE_RUNNING_TASK_SECONDS', 1200),
];

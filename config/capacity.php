<?php

return [
    'alerts' => [
        'enabled' => (bool) env('CAPACITY_ALERTS_ENABLED', true),
        'thresholds' => collect(explode(',', (string) env('CAPACITY_ALERT_THRESHOLDS', '70,80,85,90')))
            ->map(fn (string $threshold): int => (int) trim($threshold))
            ->filter(fn (int $threshold): bool => $threshold > 0 && $threshold <= 100)
            ->unique()
            ->sort()
            ->values()
            ->all(),
        'cooldown_minutes' => max(1, (int) env('CAPACITY_ALERT_COOLDOWN_MINUTES', 60)),
        'log_channel' => env('CAPACITY_ALERT_LOG_CHANNEL'),
        'webhook_url' => env('CAPACITY_ALERT_WEBHOOK_URL'),
    ],

    'paths' => [
        'disk' => env('CAPACITY_DISK_PATH', storage_path()),
        'backups' => env('CAPACITY_BACKUPS_PATH', '/root/backups'),
        'docker_logs' => env('CAPACITY_DOCKER_LOGS_PATH', '/var/lib/docker/containers'),
    ],

    'dashboard_exports' => [
        'max_files' => max(0, (int) env('DASHBOARD_EXPORT_MAX_FILES', 200)),
        'max_bytes' => max(0, (int) env('DASHBOARD_EXPORT_MAX_BYTES', 536870912)),
    ],

    'database' => [
        'max_bytes' => max(0, (int) env('CAPACITY_DATABASE_MAX_BYTES', 0)),
        'table_limit' => max(1, (int) env('CAPACITY_TABLE_LIMIT', 15)),
    ],
];

<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fleet:auto-sync')->everyFiveMinutes()->withoutOverlapping(120);

if (config('dashboard.sync.enabled', false)) {
    Schedule::command('fleet:sync-report-stats --date=yesterday')
        ->dailyAt(config('dashboard.sync.daily_time', '02:30'))
        ->timezone(config('app.timezone'))
        ->withoutOverlapping((int) config('dashboard.sync.overlap_minutes', 120));
}

if (config('fleet.foreign_geofence.monitoring_enabled', false)) {
    $interval = max(1, min(59, (int) config('fleet.foreign_geofence.monitoring_interval_minutes', 5)));

    Schedule::command('fleet:monitor-foreign-geofences')
        ->cron("*/{$interval} * * * *")
        ->timezone(config('app.timezone'))
        ->withoutOverlapping((int) config('fleet.foreign_geofence.monitoring_lock_seconds', 240));
}

<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fleet:auto-sync')->everyFiveMinutes()->withoutOverlapping(120);

if (config('dashboard.sync.enabled', false)) {
    Schedule::command('fleet:sync-report-stats --date=yesterday')
        ->dailyAt(config('dashboard.sync.daily_time', '02:30'))
        ->timezone(config('app.timezone'))
        ->withoutOverlapping((int) config('dashboard.sync.overlap_minutes', 120));
}

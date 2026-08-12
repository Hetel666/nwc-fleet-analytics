<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('dashboard-reports:sync-daily')
    ->dailyAt('00:00')
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping();

Schedule::command('dashboard-reports:pipeline-tick')
    ->hourly()
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping(30);

Schedule::command('fleet:capacity-check')
    ->hourly()
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping(30);

Schedule::command('fleet:prune-dashboard-exports --skip-when-sync-active')
    ->dailyAt('04:30')
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping();

if (config('wialon_catalog.auto_sync_enabled', false)) {
    Schedule::command('wialon-catalog:sync')
        ->dailyAt((string) config('wialon_catalog.auto_sync_time', '23:00'))
        ->timezone(config('app.timezone', 'Asia/Baku'))
        ->withoutOverlapping();
}

<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('dashboard-reports:queue-sync --daily --force')
    ->dailyAt('00:00')
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping(180);

Schedule::command('nighttime-efficiency:sync-last-completed-shift --force')
    ->dailyAt('08:30')
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping(180);

Schedule::command('dashboard-reports:pipeline-tick')
    ->hourly()
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping(30);

Schedule::command('fleet:prune-dashboard-exports --skip-when-sync-active')
    ->dailyAt('04:30')
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping();

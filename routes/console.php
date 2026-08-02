<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('dashboard-reports:queue-sync --daily --force')
    ->dailyAt('18:00')
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping(180);

Schedule::command('fleet:prune-dashboard-exports')
    ->dailyAt('01:00')
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping();

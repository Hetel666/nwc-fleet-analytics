<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fleet:auto-sync --force')
    ->dailyAt('18:00')
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping(180);

Schedule::command('fleet:prune-dashboard-exports')
    ->dailyAt('01:00')
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping();

Schedule::command('daytime-efficiency:sync-yesterday')
    ->dailyAt('19:00')
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping(180);

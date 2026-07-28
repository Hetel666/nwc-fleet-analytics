<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fleet:auto-sync --force')
    ->dailyAt('00:00')
    ->timezone(config('app.timezone', 'Asia/Baku'))
    ->withoutOverlapping(180);

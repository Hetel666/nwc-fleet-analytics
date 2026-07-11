<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fleet:sync-daily')->dailyAt('02:10')->withoutOverlapping();
Schedule::command('fleet:sync-units')->hourly()->withoutOverlapping();

<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fleet:auto-sync')->everyFiveMinutes()->withoutOverlapping(120);
Schedule::command('fleet:sync-geozon-api')->everyThirtyMinutes()->withoutOverlapping(60);
Schedule::command('fleet:plan-shift-sync')->everyTenMinutes()->withoutOverlapping(30);
Schedule::command('fleet:run-shift-sync --limit=5')->everyTenMinutes()->withoutOverlapping(30);

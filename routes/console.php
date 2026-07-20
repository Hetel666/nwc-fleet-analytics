<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fleet:auto-sync')->everyFiveMinutes()->withoutOverlapping(120);
Schedule::command('fleet:sync-geozon-api')->everyThirtyMinutes()->withoutOverlapping(60);

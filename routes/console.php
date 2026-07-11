<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fleet:auto-sync')->everyFiveMinutes()->withoutOverlapping(120);

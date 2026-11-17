<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('betterlink:check-slas')->everyMinute();
Schedule::command('betterlink:dispatch-followups')->hourly();
Schedule::command('betterlink:detect-outbreaks')->hourly();
Schedule::command('betterlink:remind-stale-outlets')->dailyAt((string) config('betterlink.outlets.stale_reminder_hour', '07:30'));

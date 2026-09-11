<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Self-monitoring loop.
|
| Both run every minute; the actual cadence lives in schedule_settings and is
| enforced inside each command, so it can be changed from /admin/schedule
| without editing this file or restarting the scheduler.
*/
Schedule::command('health:ping --scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('health:detect --scheduled')->everyMinute()->withoutOverlapping();

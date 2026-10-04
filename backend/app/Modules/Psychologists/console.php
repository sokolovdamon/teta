<?php

use App\Modules\Psychologists\Services\ActivityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// ST-09: on the 1st at 00:30 MSK profiles without last month's supervision become inactive (DEC-37).
Artisan::command('psy:activity-rollover', function (ActivityService $activity) {
    $stats = $activity->monthlyRollover();
    $this->info("Inactivated: {$stats['inactivated']}, reset: {$stats['reset']}");
})->purpose('Monthly supervision requirement rollover');

Schedule::command('psy:activity-rollover')->monthlyOn(1, '00:30')->timezone('Europe/Moscow')->withoutOverlapping();

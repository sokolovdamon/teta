<?php

use App\Modules\Booking\Services\OutcomeService;
use App\Modules\Booking\Services\ReminderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// ST-01 lifecycle: paid → in_progress at the start, automatic outcome after P-OUTCOME-DEADLINE by the session log,
// free bookings become paid at the charge time, pending client choices are refunded at their deadline.
Artisan::command('book:advance', function (OutcomeService $outcomes) {
    $stats = $outcomes->advance();
    $this->info(collect($stats)->map(fn ($v, $k) => "{$k}: {$v}")->implode(', '));
})->purpose('Advance session statuses (start, automatic outcome, choice deadlines)');

// P-REMINDERS: 24 h and 1 h before the session; notification preferences are respected.
Artisan::command('book:send-reminders', function (ReminderService $reminders) {
    $this->info('Reminders sent: '.$reminders->send());
})->purpose('Send session reminders');

Schedule::command('book:advance')->everyMinute()->withoutOverlapping();
Schedule::command('book:send-reminders')->everyFiveMinutes()->withoutOverlapping();

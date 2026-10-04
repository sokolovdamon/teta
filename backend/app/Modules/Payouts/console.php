<?php

use App\Modules\Payouts\Services\PayoutRegistryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// SEQ-08: weekly registry on the payout day of P-PAYOUT-PERIOD (Monday) at 03:00 MSK. The command runs daily and
// acts only on the payout day, so the schedule follows the parameter without reading settings at boot.
Artisan::command('payout:build-weekly-registry {--force : Build now even if today is not the payout day} {--at= : Run as of this moment (Y-m-d H:i, MSK)}', function (PayoutRegistryService $service) {
    $at = $this->option('at') ? CarbonImmutable::parse($this->option('at'), config('platform.timezone')) : CarbonImmutable::now();
    if (! $this->option('force') && ! PayoutRegistryService::isPayoutDay($at)) {
        $this->info('Not a payout day.');

        return 0;
    }
    $registry = $service->build($at);
    $summary = $service->summary($registry);
    $this->info("Registry {$registry->id} ({$registry->period_start->toDateString()} – {$registry->period_end->toDateString()}): {$summary['lines']} lines, {$summary['amount']} kopecks, blocked {$summary['blocked']}, deferred {$summary['deferred']}, status {$registry->status}.");

    return 0;
})->purpose('Build the weekly payout registry (SEQ-08)');

// ST-07 "Статус неизвестен": no webhook within P-PAYOUT-WEBHOOK-WAIT → ask the gateway with the same idempotency key.
Artisan::command('payout:check-unknown', function (PayoutRegistryService $service) {
    $this->info('Checked: '.$service->checkUnknown());
})->purpose('Query the status of payouts without a webhook');

Schedule::command('payout:build-weekly-registry')->dailyAt('03:00')->timezone('Europe/Moscow')->withoutOverlapping()->onOneServer();
Schedule::command('payout:check-unknown')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

<?php

use App\Modules\Promo\Services\PromoService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// ST-17: scheduled → active at the start of the period; active / exhausted → expired after its end.
Artisan::command('promo:sync-statuses', function (PromoService $promo) {
    $stats = $promo->syncStatuses();
    $this->info("Activated: {$stats['activated']}, expired: {$stats['expired']}");
})->purpose('Move promo codes by their validity period (ST-17)');

Schedule::command('promo:sync-statuses')->everyFiveMinutes()->withoutOverlapping();

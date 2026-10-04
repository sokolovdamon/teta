<?php

use App\Modules\Instance\Models\PartnerInstance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// ST-20: no heartbeat for more than 15 minutes → "Недоступен".
Artisan::command('instance:check-partners', function () {
    PartnerInstance::where('status', 'operational')
        ->where(fn ($q) => $q->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', now()->subMinutes(15)))
        ->each(fn (PartnerInstance $p) => $p->transitionTo('unavailable', reason: 'no heartbeat for 15 minutes'));
})->purpose('Mark partner instances without heartbeat as unavailable');

Schedule::command('instance:check-partners')->everyFiveMinutes()->withoutOverlapping();

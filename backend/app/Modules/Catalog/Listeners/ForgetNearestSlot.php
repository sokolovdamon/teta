<?php

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Services\NearestSlots;
use App\Support\Events\DomainEvent;
use App\Support\Events\DomainEventListener;
use Illuminate\Support\Facades\DB;

/** A booking or profile change may change the nearest free slot shown in the catalog: drop the cached value (idempotent). */
class ForgetNearestSlot implements DomainEventListener
{
    public function handle(DomainEvent $event): void
    {
        $psychologistId = match ($event->aggregate_type) {
            'psychologist' => $event->aggregate_id,
            'therapy_session' => DB::table('therapy_sessions')->where('id', $event->aggregate_id)->value('psychologist_id'),
            default => null,
        };
        if ($psychologistId) {
            NearestSlots::forget($psychologistId);
        }
    }
}

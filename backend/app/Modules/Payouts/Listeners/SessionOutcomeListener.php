<?php

namespace App\Modules\Payouts\Listeners;

use App\Modules\Booking\Models\TherapySession;
use App\Modules\Payouts\Services\AccrualService;
use App\Support\Events\DomainEvent;
use App\Support\Events\DomainEventListener;

/**
 * book.session.{held, client_no_show, cancelled_by_client, psy_no_show, tech_issue} → ST-06.
 * The session is loaded by aggregate id and its current fields decide the accrual, so repeated, late or
 * out-of-order events converge (idempotent).
 */
class SessionOutcomeListener implements DomainEventListener
{
    public function __construct(private AccrualService $accruals) {}

    public function handle(DomainEvent $event): void
    {
        $session = $event->aggregate_id ? TherapySession::find($event->aggregate_id) : null;
        if (! $session) {
            return;
        }
        $payload = $event->payload ?? [];
        $cancelKind = ($payload['to'] ?? null) === TherapySession::CANCELLED_BY_CLIENT ? ($payload['kind'] ?? null) : null;

        $this->accruals->syncSession($session, $cancelKind, $event->actor_id, 'event:'.$event->id);
    }
}

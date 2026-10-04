<?php

namespace App\Modules\Promo\Listeners;

use App\Modules\Booking\Models\TherapySession;
use App\Modules\Promo\Services\ReferralService;
use App\Support\Events\DomainEvent;
use App\Support\Events\DomainEventListener;

/** book.session.paid → the inviter's reward after the friend's first paid session (SEQ-19). Idempotent per invite. */
class ReferralRewardListener implements DomainEventListener
{
    public function __construct(private ReferralService $referrals) {}

    public function handle(DomainEvent $event): void
    {
        $session = $event->aggregate_id ? TherapySession::find($event->aggregate_id) : null;
        if (! $session || ($session->paid_at === null && $session->status !== TherapySession::PAID)) {
            return;
        }
        $this->referrals->onSessionPaid($session);
    }
}

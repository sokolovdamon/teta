<?php

namespace App\Modules\Promo\Listeners;

use App\Models\User;
use App\Modules\Promo\Services\ReferralService;
use App\Support\Events\DomainEvent;
use App\Support\Events\DomainEventListener;

/** auth.user.registered {referral_code} → invite and the friend's first-session code (SEQ-19). Idempotent per invitee. */
class ReferralRegistrationListener implements DomainEventListener
{
    public function __construct(private ReferralService $referrals) {}

    public function handle(DomainEvent $event): void
    {
        $code = trim((string) ($event->payload['referral_code'] ?? ''));
        if ($code === '' || ! $event->aggregate_id) {
            return;
        }
        $user = User::find($event->aggregate_id);
        if ($user) {
            $this->referrals->onRegistered($user, $code);
        }
    }
}

<?php

namespace App\Modules\Payouts\Listeners;

use App\Modules\Booking\Models\TherapySession;
use App\Modules\Payments\Models\ChargeComplaint;
use App\Modules\Payouts\Services\AccrualService;
use App\Support\Events\DomainEvent;
use App\Support\Events\DomainEventListener;

/**
 * pay.complaint.refunded {session_id, refund_amount, share_percent} → the accrual is reversed by the same share
 * (SEQ-06, Q-56); after a payout it becomes a negative correction (BR-PAYOUT-12). Idempotent per complaint.
 */
class ComplaintRefundedListener implements DomainEventListener
{
    public function __construct(private AccrualService $accruals) {}

    public function handle(DomainEvent $event): void
    {
        $payload = $event->payload ?? [];
        $complaint = $event->aggregate_id ? ChargeComplaint::find($event->aggregate_id) : null;
        $sessionId = $payload['session_id'] ?? $complaint?->therapy_session_id;
        $session = $sessionId ? TherapySession::find($sessionId) : null;
        if (! $session) {
            return;
        }
        $refund = $payload['refund_amount'] ?? $complaint?->refund_amount;

        $this->accruals->reverseForComplaint(
            $session,
            (string) ($complaint?->id ?? $event->aggregate_id ?? $event->id),
            $payload['share_percent'] ?? null,
            $refund !== null ? (int) $refund : null,
            $event->actor_id,
        );
    }
}

<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Contracts\CorporateCoverage;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\BalanceService;
use App\Modules\Promo\Contracts\PromoCodes;
use Illuminate\Database\Eloquent\Model;

/**
 * Money effects on a session: it becomes paid (charge, balance, free, corporate) or its money is returned to the
 * client's cabinet balance (Q-52). Certificate funds return as certificate funds in the same proportion, so they
 * can never be withdrawn to a card (DEC-43).
 */
class SessionPayments
{
    public function __construct(
        private BalanceService $balance,
        private PromoCodes $promo,
        private CorporateCoverage $coverage,
    ) {}

    /** ST-01 booked → paid. Idempotent: returns false if the session is no longer booked. */
    public function markPaid(TherapySession $session, string $source, int $card, int $balance, int $certificate, ?Payment $payment, ?string $actorId = null, string $kind = 'charged'): bool
    {
        $session = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
        if ($session->status !== TherapySession::BOOKED) {
            return false;
        }
        $charged = $card + $balance;
        $session->transitionTo(TherapySession::PAID, $actorId, $kind, [
            'paid_at' => now(),
            'payment_source' => $source,
            'payment_id' => $payment?->id,
            'paid_card' => $card,
            'paid_balance' => $balance,
            'paid_certificate' => $certificate,
            'amount_charged' => $charged,
        ], [
            'kind' => $kind,
            'price' => $session->price,
            'discount' => $session->discount,
            'amount_charged' => $charged,
            'payment_source' => $source,
        ]);
        if ($session->hasPromo()) {
            $this->promo->consume($session);
        }

        return true;
    }

    /** Return part of the money retained for the session to the client's balance. Returns the credited amount. */
    public function creditToBalance(TherapySession $session, int $amount, string $reason, ?string $actorId = null, ?string $comment = null, ?Model $source = null): int
    {
        $session = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
        $amount = min($amount, $session->retainedAmount());
        if ($amount <= 0) {
            return 0;
        }
        $charged = (int) $session->amount_charged;
        $certificate = $charged > 0 ? min($amount, intdiv($amount * (int) $session->paid_certificate + intdiv($charged, 2), $charged)) : 0;
        $source ??= $session;

        $this->balance->credit($session->client_id, $amount - $certificate, $reason, $source, $session->payment_id ? Payment::find($session->payment_id) : null, false, $comment, $actorId);
        $this->balance->credit($session->client_id, $certificate, $reason, $source, null, true, $comment, $actorId);
        $session->forceFill(['balance_refunded' => (int) $session->balance_refunded + $amount])->save();

        return $amount;
    }

    /** Full return after a psychologist's cancel / no-show, a technical issue or a platform cancel: money → balance, corporate → limit. */
    public function refundAll(TherapySession $session, string $reason, ?string $actorId = null): int
    {
        if ($session->isCorporate()) {
            $this->coverage->release($session);

            return 0;
        }

        return $this->creditToBalance($session, $session->retainedAmount(), $reason, $actorId);
    }
}

<?php

namespace App\Modules\Payouts\Services;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Notifications\Notifier;
use App\Modules\Payouts\Models\Accrual;
use App\Support\Money;
use App\Support\Settings\Settings;
use App\Support\StateMachine\StateTransition;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ST-06 accruals (DEC-20, DEC-37, DEC-43, DEC-57, Q-56, BR-PAYOUT-01…04, BR-PAYOUT-08, BR-PAYOUT-12, BR-B2B-11).
 *
 * - Session "held" → 70 % (100 − P-COMMISSION) of the full fixed price: promo discounts and certificate funds
 *   never reduce it; a corporate session accrues from the psychologist's price, not the company price.
 * - Client no-show, or client cancellation after the charge → 70 % of the retained amount [Q-56].
 * - Psychologist cancel or no-show, technical issue, change of psychologist (DEC-48) → nothing.
 * - Outcome corrections by an admin reverse or create the accrual: one live accrual per session.
 * - An approved complaint reverses the same share; after the money left (in registry or paid) the reversal becomes
 *   a negative correction carried into the next payouts and the accrual becomes "corrected" (BR-PAYOUT-12).
 * - Supervision held in "payment" mode → price × (100 − P-SUPERV-COMMISSION) to the supervisor (DEC-37 p. 7).
 */
class AccrualService
{
    private const OUTCOME_LABELS = [
        TherapySession::HELD => 'сессия проведена',
        TherapySession::CLIENT_NO_SHOW => 'неявка клиента',
        TherapySession::PSY_NO_SHOW => 'неявка психолога',
        TherapySession::TECH_ISSUE => 'техническая проблема',
        TherapySession::CANCELLED_BY_CLIENT => 'отмена клиентом',
        TherapySession::CANCELLED_BY_PSY => 'отмена психологом',
        TherapySession::CANCELLED_BY_SYSTEM => 'отмена платформой',
    ];

    public function __construct(private PayeeBalanceService $balances, private Notifier $notifier) {}

    /**
     * Bring the session's accrual in line with its current outcome. Idempotent: repeated or out-of-order events
     * converge on the same ledger.
     *
     * @param  string|null  $cancelKind  "kind" from the cancellation event context (late_cancel | change_psychologist | …)
     */
    public function syncSession(TherapySession $session, ?string $cancelKind = null, ?string $actorId = null, ?string $eventKey = null): ?Accrual
    {
        $session->loadMissing('psychologist');
        $userId = $session->psychologist?->user_id;
        if (! $userId) {
            return null;
        }

        $result = DB::transaction(function () use ($session, $cancelKind, $actorId, $eventKey, $userId) {
            $this->balances->lock($userId);
            $entitlement = $this->entitlement($session, $cancelKind);
            $live = $this->liveSessionAccrual($session);

            if ($entitlement && $live) {
                if ($live->kind !== $entitlement['kind']) {
                    $expected = Money::share($entitlement['base'], 100 - Settings::int('P-COMMISSION'));
                    if ($expected === (int) $live->amount) {
                        $live->forceFill(['kind' => $entitlement['kind'], 'reason' => $entitlement['reason']])->save();
                    } else {
                        $this->reverse($live, $this->remaining($live), $eventKey ?? 'outcome:'.Str::uuid(), 'Итог сессии исправлен: '.$this->outcomeLabel($session), $actorId);
                        $live = $this->createSessionAccrual($session, $userId, $entitlement, $actorId);
                    }
                }
                $this->balances->refresh($userId);

                return ['accrual' => $live, 'adjusted' => null];
            }

            if ($entitlement) {
                $accrual = $this->createSessionAccrual($session, $userId, $entitlement, $actorId);
                $this->balances->refresh($userId);

                return ['accrual' => $accrual, 'adjusted' => null];
            }

            if ($live) {
                $amount = $this->remaining($live);
                $reason = 'Итог сессии исправлен: '.$this->outcomeLabel($session);
                $this->reverse($live, $amount, $eventKey ?? 'outcome:'.Str::uuid(), $reason, $actorId);
                $this->balances->refresh($userId);

                return ['accrual' => null, 'adjusted' => ['user_id' => $userId, 'amount' => $amount, 'reason' => $reason]];
            }

            return ['accrual' => null, 'adjusted' => null];
        });

        $this->notifyAdjusted($result['adjusted']);

        return $result['accrual'];
    }

    /**
     * Approved complaint refund (SEQ-06): reverse the same share of the accrual (Q-56). Idempotent per complaint.
     */
    public function reverseForComplaint(TherapySession $session, string $complaintKey, float|int|string|null $sharePercent, ?int $refundAmount = null, ?string $actorId = null): void
    {
        $session->loadMissing('psychologist');
        $userId = $session->psychologist?->user_id;
        if (! $userId) {
            return;
        }

        $adjusted = DB::transaction(function () use ($session, $complaintKey, $sharePercent, $refundAmount, $actorId, $userId) {
            $this->balances->lock($userId);
            $live = $this->liveSessionAccrual($session);
            if (! $live || $live->hasAdjustment('complaint:'.$complaintKey)) {
                return null;
            }

            $share = $this->complaintShare($session, $sharePercent, $refundAmount);
            if ($share <= 0) {
                return null;
            }
            $amount = $share >= 100 ? $this->remaining($live) : (int) round(((int) $live->amount) * $share / 100);
            $label = rtrim(rtrim(number_format(min($share, 100), 2, ',', ''), '0'), ',');
            $reason = "Возврат клиенту по жалобе на списание: {$label} % оплаты";
            $done = $this->reverse($live, $amount, 'complaint:'.$complaintKey, $reason, $actorId);
            $this->balances->refresh($userId);

            return $done ? ['user_id' => $userId, 'amount' => min($amount, $done), 'reason' => $reason] : null;
        });

        $this->notifyAdjusted($adjusted);
    }

    /**
     * SUPERV (wave 2) calls this when a supervision is held in "payment" mode (DEC-37 p. 7, BR-PAYOUT-08).
     * Idempotent per meeting model and supervisor: pass one model per paid participation if a group meeting has
     * several payers (e.g. the participant's booking).
     */
    public function accrueSupervision(Model $meeting, User $supervisor, int $price): Accrual
    {
        return DB::transaction(function () use ($meeting, $supervisor, $price) {
            $this->balances->lock($supervisor->id);
            $existing = Accrual::where('source_type', $meeting->getMorphClass())
                ->where('source_id', $meeting->getKey())
                ->where('user_id', $supervisor->id)
                ->where('kind', Accrual::KIND_SUPERVISION)
                ->where('status', '!=', 'reversed')
                ->first();
            if ($existing) {
                return $existing;
            }

            $commission = Settings::int('P-SUPERV-COMMISSION');
            $accrual = new Accrual([
                'user_id' => $supervisor->id,
                'source_type' => $meeting->getMorphClass(),
                'source_id' => $meeting->getKey(),
                'kind' => Accrual::KIND_SUPERVISION,
                'base_amount' => $price,
                'commission_percent' => $commission,
                'amount' => Money::share($price, 100 - $commission),
                'reason' => 'Супервизия проведена',
                'occurred_at' => now(),
                'meta' => ['price' => $price],
            ]);
            $accrual->forceFill(['status' => 'accrued'])->save();
            $accrual->recordInitialState(null, ['kind' => $accrual->kind, 'amount' => (int) $accrual->amount]);
            $this->balances->refresh($supervisor->id);

            return $accrual;
        });
    }

    /** Reverse a supervision accrual (cancellation or refund of a held supervision), fully or by share. */
    public function reverseSupervision(Model $meeting, User $supervisor, string $reason, int $sharePercent = 100, ?string $key = null): void
    {
        $adjusted = DB::transaction(function () use ($meeting, $supervisor, $reason, $sharePercent, $key) {
            $this->balances->lock($supervisor->id);
            $accrual = Accrual::where('source_type', $meeting->getMorphClass())
                ->where('source_id', $meeting->getKey())
                ->where('user_id', $supervisor->id)
                ->where('kind', Accrual::KIND_SUPERVISION)
                ->where('status', '!=', 'reversed')
                ->lockForUpdate()
                ->get()
                ->first(fn (Accrual $a) => $this->remaining($a) > 0);
            if (! $accrual) {
                return null;
            }
            $amount = $sharePercent >= 100 ? $this->remaining($accrual) : (int) round(((int) $accrual->amount) * $sharePercent / 100);
            $done = $this->reverse($accrual, $amount, $key ?? 'supervision:'.Str::uuid(), $reason);
            $this->balances->refresh($supervisor->id);

            return $done ? ['user_id' => $supervisor->id, 'amount' => $done, 'reason' => $reason] : null;
        });

        $this->notifyAdjusted($adjusted);
    }

    /** What is left to reverse: amount − reversed − corrections already issued (not folded). */
    public function remaining(Accrual $accrual): int
    {
        if ($accrual->status === 'reversed' || (int) $accrual->amount <= 0) {
            return 0;
        }
        $corrected = (int) Accrual::where('correction_of_id', $accrual->id)->where('status', '!=', 'reversed')->sum('amount');

        return max(0, (int) $accrual->amount - (int) $accrual->reversed_amount + $corrected);
    }

    /**
     * Reverse $amount of an accrual. Unpaid → reversed_amount grows (ST-06 "Начислено → Начислено/Сторнировано");
     * committed to a payout (in registry, paid, corrected) → a negative correction accrual (BR-PAYOUT-12).
     * Returns the reversed amount (0 when nothing was done).
     */
    public function reverse(Accrual $accrual, int $amount, string $key, string $reason, ?string $actorId = null): int
    {
        $accrual = Accrual::whereKey($accrual->id)->lockForUpdate()->firstOrFail();
        if ($accrual->hasAdjustment($key)) {
            return 0;
        }
        $amount = min($amount, $this->remaining($accrual));
        if ($amount <= 0) {
            return 0;
        }

        $entry = ['key' => $key, 'amount' => $amount, 'reason' => $reason, 'at' => now()->toIso8601String()];

        if ($accrual->status === 'accrued') {
            $reversed = (int) $accrual->reversed_amount + $amount;
            $to = (int) $accrual->amount - $reversed <= 0 ? 'reversed' : 'accrued';
            $accrual->transitionTo($to, $actorId, $reason, [
                'reversed_amount' => $reversed,
                'reason' => $reason,
                'adjustments' => [...($accrual->adjustments ?? []), ['type' => 'reversal', ...$entry]],
            ], ['amount' => $amount, 'key' => $key]);

            return $amount;
        }

        if (in_array($accrual->status, ['in_registry', 'paid', 'corrected'], true)) {
            $correction = new Accrual([
                'user_id' => $accrual->user_id,
                'source_type' => $accrual->getMorphClass(),
                'source_id' => $accrual->id,
                'kind' => Accrual::KIND_CORRECTION,
                'base_amount' => $amount,
                'commission_percent' => $accrual->commission_percent,
                'amount' => -$amount,
                'reason' => $reason,
                'occurred_at' => now(),
                'correction_of_id' => $accrual->id,
                'meta' => ['key' => $key, 'of_kind' => $accrual->kind, ...array_intersect_key($accrual->meta ?? [], array_flip(['session_id', 'session_starts_at', 'format']))],
            ]);
            $correction->forceFill(['status' => 'accrued'])->save();
            $correction->recordInitialState($actorId, ['kind' => Accrual::KIND_CORRECTION, 'amount' => -$amount, 'correction_of' => $accrual->id]);

            $accrual->forceFill([
                'reason' => $reason,
                'adjustments' => [...($accrual->adjustments ?? []), ['type' => 'correction', 'correction_id' => $correction->id, ...$entry]],
            ])->save();
            if ($accrual->status === 'paid') {
                $accrual->transitionTo('corrected', $actorId, $reason, [], ['amount' => $amount, 'key' => $key]);
            }

            return $amount;
        }

        return 0;
    }

    /**
     * An accrual came back to "accrued" (payout rejected or excluded): corrections that have not left yet are folded
     * into it, so an unpaid accrual never carries pending corrections.
     */
    public function foldPendingCorrections(Accrual $accrual): void
    {
        $pending = Accrual::where('correction_of_id', $accrual->id)->where('status', 'accrued')->lockForUpdate()->get();
        if ($pending->isEmpty()) {
            return;
        }
        $total = 0;
        foreach ($pending as $correction) {
            $total += abs((int) $correction->amount);
            $correction->transitionTo('reversed', null, 'Учтена в начислении до выплаты', ['reason' => 'Учтена в начислении до выплаты']);
        }
        $accrual->refresh();
        $reversed = (int) $accrual->reversed_amount + $total;
        $to = (int) $accrual->amount - $reversed <= 0 ? 'reversed' : 'accrued';
        $accrual->transitionTo($to, null, $accrual->reason, ['reversed_amount' => min($reversed, (int) $accrual->amount)], ['folded' => $total]);
    }

    /** @return array{kind: string, base: int, reason: string, occurred_at: CarbonInterface}|null */
    private function entitlement(TherapySession $session, ?string $cancelKind): ?array
    {
        $price = (int) $session->price ?: (int) ($session->psychologist?->priceFor($session->format) ?? 0);
        $entitlement = match ($session->status) {
            TherapySession::HELD => [
                'kind' => Accrual::KIND_SESSION, 'base' => $price, 'reason' => 'Сессия проведена',
                'occurred_at' => $session->outcome_at ?? now(),
            ],
            TherapySession::CLIENT_NO_SHOW => [
                'kind' => Accrual::KIND_CLIENT_NO_SHOW, 'base' => $price, 'reason' => 'Клиент не пришёл на сессию, оплата удержана',
                'occurred_at' => $session->outcome_at ?? now(),
            ],
            TherapySession::CANCELLED_BY_CLIENT => $this->lateCancelEntitlement($session, $price, $cancelKind),
            default => null,
        };

        return $entitlement && $entitlement['base'] > 0 ? $entitlement : null;
    }

    private function lateCancelEntitlement(TherapySession $session, int $price, ?string $cancelKind): ?array
    {
        if ($session->paid_at === null) {
            return null;
        }
        $kind = $cancelKind ?? $this->cancelKindFromHistory($session);
        if ($kind !== null && $kind !== 'late_cancel') {
            return null;
        }
        $refund = (int) (($session->params ?? [])['P-LATE-CANCEL-REFUND'] ?? Settings::int('P-LATE-CANCEL-REFUND'));

        return [
            'kind' => Accrual::KIND_LATE_CANCEL,
            'base' => $price - Money::share($price, max(0, min(100, $refund))),
            'reason' => 'Клиент отменил сессию после списания, оплата удержана',
            'occurred_at' => $session->cancelled_at ?? now(),
        ];
    }

    private function cancelKindFromHistory(TherapySession $session): ?string
    {
        $transition = StateTransition::where('model_type', $session->getMorphClass())
            ->where('model_id', $session->id)
            ->where('to', TherapySession::CANCELLED_BY_CLIENT)
            ->latest('created_at')
            ->first();

        return $transition?->context['kind'] ?? null;
    }

    private function liveSessionAccrual(TherapySession $session): ?Accrual
    {
        return Accrual::where('source_type', $session->getMorphClass())
            ->where('source_id', $session->id)
            ->whereIn('kind', Accrual::SESSION_KINDS)
            ->where('status', '!=', 'reversed')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get()
            ->first(fn (Accrual $a) => $this->remaining($a) > 0);
    }

    /** @param  array{kind: string, base: int, reason: string, occurred_at: CarbonInterface}  $entitlement */
    private function createSessionAccrual(TherapySession $session, string $userId, array $entitlement, ?string $actorId): Accrual
    {
        $commission = Settings::int('P-COMMISSION');
        $accrual = new Accrual([
            'user_id' => $userId,
            'source_type' => $session->getMorphClass(),
            'source_id' => $session->id,
            'kind' => $entitlement['kind'],
            'base_amount' => $entitlement['base'],
            'commission_percent' => $commission,
            'amount' => Money::share($entitlement['base'], 100 - $commission),
            'reason' => $entitlement['reason'],
            'occurred_at' => $entitlement['occurred_at'],
            'meta' => [
                'session_id' => $session->id,
                'session_starts_at' => $session->starts_at?->toIso8601String(),
                'format' => $session->format,
                'price' => (int) $session->price,
                'discount' => (int) $session->discount,
                'payment_source' => $session->payment_source,
                'corporate' => $session->isCorporate(),
                'client_id' => $session->client_id,
            ],
        ]);
        $accrual->forceFill(['status' => 'accrued'])->save();
        $accrual->recordInitialState($actorId, ['kind' => $accrual->kind, 'amount' => (int) $accrual->amount, 'session_id' => $session->id]);

        return $accrual;
    }

    private function complaintShare(TherapySession $session, float|int|string|null $sharePercent, ?int $refundAmount): float
    {
        if ($sharePercent !== null && $sharePercent !== '') {
            return max(0.0, min(100.0, (float) $sharePercent));
        }
        $paid = (int) $session->amount_due ?: max(0, (int) $session->price - (int) $session->discount);
        if ($refundAmount !== null && $paid > 0) {
            return max(0.0, min(100.0, $refundAmount * 100 / $paid));
        }

        return 100.0;
    }

    private function outcomeLabel(TherapySession $session): string
    {
        return self::OUTCOME_LABELS[$session->status] ?? $session->status;
    }

    /** @param  array{user_id: string, amount: int, reason: string}|null  $adjusted */
    private function notifyAdjusted(?array $adjusted): void
    {
        if (! $adjusted || $adjusted['amount'] <= 0) {
            return;
        }
        $user = User::find($adjusted['user_id']);
        if ($user) {
            $this->notifier->send($user, 'payout.accrual_adjusted', [
                'amount' => Money::format($adjusted['amount']), 'reason' => $adjusted['reason'],
            ], '/pro/stats');
        }
    }
}

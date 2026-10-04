<?php

namespace App\Modules\Booking\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Support\BookingError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Session lifecycle after payment (ST-01, SEQ-07): paid → in_progress at the start; the outcome is set by the
 * psychologist or, after P-OUTCOME-DEADLINE, automatically from the session log written by TetaMeet
 * (client_joined_at, psychologist_joined_at, joint_duration_sec, actual_duration_sec); an admin corrects it
 * in ADM-04. PAYOUT accrues from book.session.{held,client_no_show} (context: kind, price, amount_charged).
 */
class OutcomeService
{
    public const PSYCHOLOGIST_OUTCOMES = [TherapySession::HELD, TherapySession::CLIENT_NO_SHOW, TherapySession::TECH_ISSUE];

    public const OUTCOMES = [TherapySession::HELD, TherapySession::CLIENT_NO_SHOW, TherapySession::PSY_NO_SHOW, TherapySession::TECH_ISSUE];

    public function __construct(
        private CancellationService $cancellations,
        private SessionPayments $sessionPayments,
        private BookingService $booking,
        private BookingNotifications $notify,
    ) {}

    /** Psychologist marks the outcome (PRO-04). */
    public function setByPsychologist(TherapySession $session, User $psychologist, string $outcome): TherapySession
    {
        if (! in_array($outcome, self::PSYCHOLOGIST_OUTCOMES, true)) {
            BookingError::fail('Такой итог психолог не отмечает.', 'invalid_outcome', 'outcome');
        }
        $s = DB::transaction(function () use ($session, $psychologist, $outcome) {
            $s = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->startIfDue($s);
            if ($s->status !== TherapySession::IN_PROGRESS) {
                BookingError::fail('Итог можно отметить только у начавшейся сессии без итога.', 'invalid_status', 'outcome', 409);
            }
            $start = CarbonImmutable::parse($s->starts_at);
            if ($outcome === TherapySession::HELD && $this->duration($s) <= 0) {
                BookingError::fail('По журналу сессий длительность встречи нулевая: отметить «Проведена» нельзя. Выберите «Техническая проблема» или «Неявка клиента».', 'zero_duration', 'outcome');
            }
            if ($outcome === TherapySession::CLIENT_NO_SHOW) {
                $wait = (int) $s->param('P-NOSHOW-WAIT');
                if (now() < $start->addMinutes($wait)) {
                    BookingError::fail("Неявку можно отметить через {$wait} мин после начала.", 'too_early', 'outcome');
                }
                if ($s->client_joined_at && CarbonImmutable::parse($s->client_joined_at) <= $start->addMinutes($wait)) {
                    BookingError::fail('По журналу сессий клиент подключился вовремя.', 'log_contradicts', 'outcome');
                }
            }
            $this->apply($s, $outcome, $psychologist->id, 'psychologist');

            return $s;
        });
        $this->afterOutcome($s);

        return $s;
    }

    /** ADM-04: set or correct the outcome by the session log (BR-BOOK-13, BR-CANC-07). */
    public function setByAdmin(TherapySession $session, User $admin, string $outcome, string $reason): TherapySession
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            BookingError::fail('Неизвестный итог сессии.', 'invalid_outcome', 'outcome');
        }
        [$s, $from] = DB::transaction(function () use ($session, $admin, $outcome, $reason) {
            $s = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->startIfDue($s);
            $from = $s->status;
            if (! $s->canTransition($outcome)) {
                BookingError::fail('Такой переход статуса недопустим.', 'invalid_transition', 'outcome', 409);
            }
            if (in_array($s->client_choice, ['refund', 'reschedule'], true)) {
                BookingError::fail('Клиент уже получил возврат или бесплатный перенос по этой сессии — исправление итога заблокировано.', 'choice_done', 'outcome', 409);
            }
            $this->apply($s, $outcome, $admin->id, 'admin', $reason, ['corrected_from' => $from]);
            if ($s->client_choice === 'pending' && ! in_array($outcome, [TherapySession::PSY_NO_SHOW, TherapySession::TECH_ISSUE], true)) {
                $s->transitionTo('none', $admin->id, 'outcome corrected', ['choice_deadline_at' => null], ['session_id' => $s->id], 'client_choice', 'book.session.choice_cancelled');
            }
            Audit::log('ADM-04', 'session.outcome_set', $s, ['from' => $from, 'to' => $outcome], $reason, $admin->id);

            return [$s, $from];
        });
        $this->afterOutcome($s);

        return $s;
    }

    /**
     * book:advance (every minute): start paid sessions, mark free bookings paid at the charge time,
     * resolve outcomes after P-OUTCOME-DEADLINE, refund choices past their deadline, expire booking intents.
     *
     * @return array<string, int>
     */
    public function advance(): array
    {
        $stats = ['started' => 0, 'free_paid' => 0, 'resolved' => 0, 'choices' => 0, 'unpaid_cancelled' => 0, 'intents_expired' => 0];

        foreach (TherapySession::where('status', TherapySession::PAID)->where('starts_at', '<=', now())->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$stats) {
                $s = TherapySession::whereKey($id)->lockForUpdate()->first();
                if ($s && $this->startIfDue($s)) {
                    $stats['started']++;
                }
            });
        }

        // 100 % discount: no charge task; at the charge time the session becomes paid (BR-PROMO-08).
        $free = TherapySession::where('status', TherapySession::BOOKED)->where('amount_due', 0)->where('charge_due_at', '<=', now())
            ->whereDoesntHave('chargeTask')->pluck('id');
        foreach ($free as $id) {
            DB::transaction(function () use ($id, &$stats) {
                $s = TherapySession::whereKey($id)->lockForUpdate()->first();
                if ($s && $this->sessionPayments->markPaid($s, 'free', 0, 0, 0, null, null, 'free')) {
                    $stats['free_paid']++;
                }
            });
        }

        // Safety net: a booked session that reached its start without any payment flow is cancelled without retention.
        $stale = TherapySession::where('status', TherapySession::BOOKED)->where('starts_at', '<=', now())
            ->whereDoesntHave('chargeTask', fn ($q) => $q->whereIn('status', ['scheduled', 'in_progress', 'retry_wait']))
            ->pluck('id');
        foreach ($stale as $id) {
            if ($this->cancellations->cancelBySystem(TherapySession::findOrFail($id), 'unpaid', 'Сессия не была оплачена')) {
                $stats['unpaid_cancelled']++;
            }
        }

        $candidates = TherapySession::where('status', TherapySession::IN_PROGRESS)->where('starts_at', '<=', now())->get(['id', 'starts_at', 'ends_at', 'params']);
        foreach ($candidates as $c) {
            $deadline = CarbonImmutable::parse($c->starts_at)->addMinutes((int) $c->param('P-OUTCOME-DEADLINE'))->max(CarbonImmutable::parse($c->ends_at));
            if ($deadline > now()) {
                continue;
            }
            $s = DB::transaction(function () use ($c) {
                $s = TherapySession::whereKey($c->id)->lockForUpdate()->first();
                if (! $s || $s->status !== TherapySession::IN_PROGRESS) {
                    return null;
                }
                $this->apply($s, $this->resolveFromLog($s), null, 'auto');

                return $s;
            });
            if ($s) {
                $this->afterOutcome($s);
                $stats['resolved']++;
            }
        }

        $stats['choices'] = $this->cancellations->autoResolveChoices();
        $stats['intents_expired'] = $this->booking->expireIntents();

        return $stats;
    }

    /** Automatic outcome by the session log (SEQ-07). */
    public function resolveFromLog(TherapySession $s): string
    {
        $start = CarbonImmutable::parse($s->starts_at);
        $wait = (int) $s->param('P-NOSHOW-WAIT');
        $joint = (int) ($s->joint_duration_sec ?? 0);
        if ($joint >= (int) $s->param('P-AUTO-COMPLETE-MIN') * 60) {
            return TherapySession::HELD;
        }
        $psyOnTime = $s->psychologist_joined_at && CarbonImmutable::parse($s->psychologist_joined_at) <= $start->addMinutes($wait);
        if (! $psyOnTime) {
            return TherapySession::PSY_NO_SHOW;
        }
        $clientOnTime = $s->client_joined_at && CarbonImmutable::parse($s->client_joined_at) <= $start->addMinutes($wait);
        if (! $clientOnTime) {
            return TherapySession::CLIENT_NO_SHOW;
        }

        return TherapySession::TECH_ISSUE;
    }

    /** paid → in_progress once the start time has come (inside a transaction with the row locked). */
    public function startIfDue(TherapySession $s): bool
    {
        if ($s->status !== TherapySession::PAID || CarbonImmutable::parse($s->starts_at) > now()) {
            return false;
        }
        $s->transitionTo(TherapySession::IN_PROGRESS, reason: 'start time', context: [
            'kind' => 'started', 'price' => $s->price, 'amount_charged' => (int) $s->amount_charged,
        ]);

        return true;
    }

    /** @param  array<string, mixed>  $extra */
    private function apply(TherapySession $s, string $outcome, ?string $actorId, string $source, ?string $reason = null, array $extra = []): void
    {
        $s->transitionTo($outcome, $actorId, $reason ?? $source, [
            'outcome_at' => now(),
            'outcome_by' => $actorId,
            'outcome_source' => $source,
        ], [
            'kind' => $outcome,
            'by' => $source,
            'price' => $s->price,
            'amount_charged' => (int) $s->amount_charged,
            'joint_duration_sec' => $s->joint_duration_sec,
            ...$extra,
        ]);
        if (in_array($outcome, [TherapySession::PSY_NO_SHOW, TherapySession::TECH_ISSUE], true)) {
            $deadline = CarbonImmutable::now()->max(CarbonImmutable::parse($s->starts_at))->addMinutes((int) $s->param('P-OUTCOME-DEADLINE'));
            $this->cancellations->requireChoice($s, $deadline, $actorId);
        }
        if ($outcome === TherapySession::PSY_NO_SHOW) {
            $this->cancellations->qualityIncident($s, 'no_show');
        }
    }

    private function afterOutcome(TherapySession $s): void
    {
        $s = $s->fresh();
        match ($s->status) {
            TherapySession::CLIENT_NO_SHOW => $this->notify->clientNoShow($s),
            TherapySession::PSY_NO_SHOW => [$this->notify->choiceNeeded($s), $this->notify->psyNoShow($s)],
            TherapySession::TECH_ISSUE => $this->notify->choiceNeeded($s),
            default => null,
        };
    }

    private function duration(TherapySession $s): int
    {
        return max((int) ($s->joint_duration_sec ?? 0), (int) ($s->actual_duration_sec ?? 0));
    }
}

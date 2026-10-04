<?php

namespace App\Modules\Booking\Services;

use App\Models\User;
use App\Modules\Booking\Contracts\CorporateCoverage;
use App\Modules\Booking\Models\QualityIncident;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Support\BookingError;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Services\ChargeService;
use App\Modules\Promo\Contracts\PromoCodes;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Schedule\Services\SlotService;
use App\Support\Events\Outbox;
use App\Support\Money;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancellations (SEQ-03…SEQ-05, ST-01):
 *  - by the client before the charge — free (task cancelled, promo restored, corporate limit kept);
 *  - by the client after the charge — full retention (DEC-23, P-LATE-CANCEL-REFUND), context kind "late_cancel";
 *  - change of psychologist (DEC-48) — every not-held session with that psychologist, paid ones credited to the balance;
 *  - by the psychologist — the client chooses a refund to the balance or a free reschedule (BR-CANC-06);
 *  - by the system (charge deadline, blocking, rejected qualification) — full refund to the balance (BR-CANC-09).
 * Every transition carries kind, price and amount_charged in its context (PAYOUT accrues from book.session.*).
 */
class CancellationService
{
    public function __construct(
        private BookingService $booking,
        private ChargeService $charges,
        private SessionPayments $sessionPayments,
        private PromoCodes $promo,
        private CorporateCoverage $coverage,
        private SlotService $slots,
        private BookingNotifications $notify,
    ) {}

    /** @return array{session: TherapySession, kind: string, refund_amount: int, retained_amount: int} */
    public function cancelByClient(TherapySession $session, User $client, ?string $reason = null, bool $confirmLate = false): array
    {
        $result = DB::transaction(function () use ($session, $client, $reason, $confirmLate) {
            $s = $this->lockUpcoming($session);
            $charged = $this->booking->isCharged($s);
            $base = ['cancelled_at' => now(), 'cancelled_by' => $client->id, 'cancel_reason' => $reason];

            if (! $charged) {
                $this->cancelChargeTask($s, $client->id);
                $s->transitionTo(TherapySession::CANCELLED_BY_CLIENT, $client->id, $reason, [...$base, 'cancel_kind' => 'free_cancel'], [
                    'kind' => 'free_cancel', 'price' => $s->price, 'amount_charged' => (int) $s->amount_charged, 'refund_amount' => 0,
                ]);
                $this->releaseBenefits($s);

                return ['session' => $s, 'kind' => 'free_cancel', 'refund_amount' => 0, 'retained_amount' => 0];
            }

            if (! $confirmLate) {
                BookingError::fail(
                    'Оплата за эту сессию уже списана: при отмене она не вернётся. Вместо отмены можно перенести сессию на время не раньше чем через 12 часов.',
                    'late_cancel_confirmation',
                    'confirm_late',
                    409,
                );
            }
            $percent = (int) $s->param('P-LATE-CANCEL-REFUND');
            $refund = $s->isCorporate() ? 0 : Money::share($s->retainedAmount(), $percent);
            $retained = $s->retainedAmount() - $refund;
            $s->transitionTo(TherapySession::CANCELLED_BY_CLIENT, $client->id, $reason, [...$base, 'cancel_kind' => 'late_cancel'], [
                'kind' => 'late_cancel',
                'price' => $s->price,
                'amount_charged' => (int) $s->amount_charged,
                'refund_amount' => $refund,
                'retained_amount' => $retained,
            ]);
            if ($refund > 0) {
                $this->sessionPayments->creditToBalance($s, $refund, 'late_cancel', $client->id);
            }

            return ['session' => $s->fresh(), 'kind' => 'late_cancel', 'refund_amount' => $refund, 'retained_amount' => $retained];
        });

        $note = match (true) {
            $result['kind'] === 'free_cancel' && $result['session']->isCorporate() => 'Отмена бесплатна: лимит корпоративной программы сохранён.',
            $result['kind'] === 'free_cancel' => 'Оплата не списывалась, отмена бесплатна.',
            $result['refund_amount'] > 0 => 'Оплата была списана; на баланс личного кабинета возвращено '.Money::format($result['refund_amount']).'.',
            default => 'Оплата была списана и не возвращается. Если вы не согласны, подайте жалобу на списание в разделе «Платежи и баланс».',
        };
        $this->notify->cancelledByClient($result['session'], $note);

        return $result;
    }

    /**
     * DEC-48, SEQ-04: cancel every not-held upcoming session with the psychologist; paid ones are fully credited to
     * the balance (no accrual), unpaid ones are cancelled free. Emits book.psychologist.changed.
     *
     * @return array{cancelled: int, credited_amount: int}
     */
    public function changePsychologist(User $client, Psychologist $p, ?string $reason = null): array
    {
        $result = DB::transaction(function () use ($client, $p, $reason) {
            $sessions = TherapySession::where('client_id', $client->id)->where('psychologist_id', $p->id)
                ->whereIn('status', [TherapySession::BOOKED, TherapySession::PAID])
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')->lockForUpdate()->get();
            $cancelled = 0;
            $credited = 0;
            foreach ($sessions as $s) {
                $paid = $s->status === TherapySession::PAID;
                if (! $paid) {
                    $this->cancelChargeTask($s, $client->id);
                }
                $s->transitionTo(TherapySession::CANCELLED_BY_CLIENT, $client->id, 'change_psychologist', [
                    'cancelled_at' => now(),
                    'cancelled_by' => $client->id,
                    'cancel_kind' => 'change_psychologist',
                    'cancel_reason' => $reason,
                ], [
                    'kind' => 'change_psychologist',
                    'price' => $s->price,
                    'amount_charged' => (int) $s->amount_charged,
                    'refund_amount' => $paid ? $s->retainedAmount() : 0,
                ]);
                if ($paid) {
                    $credited += $this->sessionPayments->refundAll($s, 'change_psychologist', $client->id);
                } elseif ($s->isCorporate()) {
                    $this->coverage->release($s);
                }
                if ($s->hasPromo()) {
                    $this->promo->restore($s);
                }
                $cancelled++;
            }
            Outbox::record('book.psychologist.changed', $client, [
                'client_id' => $client->id,
                'psychologist_id' => $p->id,
                'at' => now()->toIso8601String(),
                'cancelled' => $cancelled,
            ], $client->id);

            return ['cancelled' => $cancelled, 'credited_amount' => $credited, 'sessions' => $sessions];
        });

        foreach ($result['sessions'] as $s) {
            $this->notify->cancelledByClient($s->fresh(), '', notifyClient: false);
        }
        if ($result['cancelled'] > 0) {
            $this->notify->changePsychologist($client, $p, $result['cancelled'], $result['credited_amount']);
        }

        return ['cancelled' => $result['cancelled'], 'credited_amount' => $result['credited_amount']];
    }

    /** SEQ-05: the psychologist (or an admin on their behalf) cancels before the start. */
    public function cancelByPsychologist(TherapySession $session, User $actor, ?string $reason = null): TherapySession
    {
        [$s, $charged] = DB::transaction(function () use ($session, $actor, $reason) {
            $s = $this->lockUpcoming($session);
            $charged = $this->booking->isCharged($s);
            $lateCancel = CarbonImmutable::parse($s->starts_at)->subMinutes((int) $s->param('P-CHARGE-OFFSET')) <= now();
            if (! $charged) {
                $this->cancelChargeTask($s, $actor->id);
            }
            $s->transitionTo(TherapySession::CANCELLED_BY_PSY, $actor->id, $reason, [
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_kind' => 'psy_cancel',
                'cancel_reason' => $reason,
            ], [
                'kind' => 'psy_cancel',
                'price' => $s->price,
                'amount_charged' => (int) $s->amount_charged,
                'late' => $lateCancel,
            ]);
            if ($charged) {
                $this->requireChoice($s, CarbonImmutable::parse($s->starts_at)->max(now()->addHour()), $actor->id);
            } else {
                $this->releaseBenefits($s);
            }
            if ($lateCancel) {
                $this->qualityIncident($s, 'late_cancel');
            }

            return [$s->fresh(), $charged];
        });
        $this->notify->cancelledByPsychologist($s, $charged);

        return $s;
    }

    /**
     * Cancel by the platform: charge deadline is handled by ChargeService; here — blocking of a participant,
     * rejected qualification, admin decision. Paid money is fully credited to the balance (BR-CANC-09).
     */
    public function cancelBySystem(TherapySession $session, string $kind, ?string $reason, ?string $actorId = null): ?TherapySession
    {
        $result = DB::transaction(function () use ($session, $kind, $reason, $actorId) {
            $s = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (! in_array($s->status, [TherapySession::BOOKED, TherapySession::PAID], true)) {
                return null;
            }
            $paid = $s->status === TherapySession::PAID;
            if (! $paid) {
                $this->cancelChargeTask($s, $actorId, force: true);
            }
            $s->transitionTo(TherapySession::CANCELLED_BY_SYSTEM, $actorId, $reason, [
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
                'cancel_kind' => $kind,
                'cancel_reason' => $reason,
            ], [
                'kind' => $kind,
                'price' => $s->price,
                'amount_charged' => (int) $s->amount_charged,
                'refund_amount' => $paid ? $s->retainedAmount() : 0,
            ]);
            $refund = 0;
            if ($paid) {
                $refund = $this->sessionPayments->refundAll($s, 'block', $actorId);
            } elseif ($s->isCorporate()) {
                $this->coverage->release($s);
            }
            if ($s->hasPromo()) {
                $this->promo->restore($s);
            }

            return [$s->fresh(), $refund, $paid];
        });
        if (! $result) {
            return null;
        }
        [$s, $refund, $paid] = $result;
        $note = match (true) {
            $paid && $s->isCorporate() => 'Лимит корпоративной программы восстановлен.',
            $paid => 'Оплата '.Money::format($refund).' полностью зачислена на баланс личного кабинета.',
            default => 'Оплата не списывалась.',
        };
        $this->notify->cancelledByPlatform($s, $note);

        return $s;
    }

    /**
     * Client's choice after a psychologist's cancel / no-show or a technical issue (BR-CANC-06, BR-BOOK-15):
     * "refund" credits the payment to the balance (corporate: limit restored), "reschedule" creates a free new
     * session in status "paid" with the payment and receipt moved to it.
     */
    public function choose(TherapySession $session, ?User $client, string $choice, ?CarbonImmutable $newStart = null): ?TherapySession
    {
        $actorId = $client?->id;
        $result = DB::transaction(function () use ($session, $client, $actorId, $choice, $newStart) {
            if ($choice === 'reschedule') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', [$session->psychologist_id]);
            }
            $s = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($client && $s->client_id !== $client->id) {
                abort(404);
            }
            if ($s->client_choice !== 'pending') {
                BookingError::fail('Выбор по этой сессии уже сделан.', 'choice_done', 'choice', 409);
            }
            $reasonCode = match ($s->status) {
                TherapySession::PSY_NO_SHOW => 'psy_no_show',
                TherapySession::TECH_ISSUE => 'tech_issue',
                default => 'psy_cancel',
            };

            if ($choice === 'refund') {
                $amount = $this->sessionPayments->refundAll($s, $reasonCode, $actorId);
                if ($s->hasPromo()) {
                    $this->promo->restore($s);
                }
                $s->transitionTo('refund', $actorId, $client ? 'client choice' : 'choice deadline', ['choice_deadline_at' => null], [
                    'kind' => 'refund', 'amount' => $amount, 'session_id' => $s->id, 'auto' => $client === null,
                ], 'client_choice', 'book.session.refund_chosen');

                return [$s->fresh(), null, $amount];
            }

            if (! $newStart) {
                throw ValidationException::withMessages(['starts_at' => 'Выберите новое время.']);
            }
            $newStart = $newStart->utc()->startOfMinute();
            $p = Psychologist::withTrashed()->findOrFail($s->psychologist_id);
            $this->slots->assertBookable($p, $newStart, $s->format);
            $new = $this->booking->createSession([
                'client_id' => $s->client_id,
                'psychologist_id' => $s->psychologist_id,
                'format' => $s->format,
                'starts_at' => $newStart,
                'ends_at' => $newStart->addMinutes((int) $s->duration_min),
                'duration_min' => $s->duration_min,
                'price' => $s->price,
                'discount' => $s->discount,
                'amount_due' => $s->amount_due,
                'promo_code_id' => $s->promo_code_id,
                'corporate_participation_id' => $s->corporate_participation_id,
                'payment_source' => $s->payment_source,
                'payment_id' => $s->payment_id,
                'paid_at' => now(),
                'paid_card' => $s->paid_card,
                'paid_balance' => $s->paid_balance,
                'paid_certificate' => $s->paid_certificate,
                'amount_charged' => $s->retainedAmount(),
                'charge_due_at' => $newStart->subMinutes((int) $s->param('P-CHARGE-OFFSET')),
                'charge_deadline_at' => $newStart->subMinutes((int) $s->param('P-CHARGE-DEADLINE')),
                'client_timezone' => $s->client_timezone,
                'client_request_ids' => $s->client_request_ids,
                'partner_email' => $s->partner_email,
                'partner_user_id' => $s->partner_user_id,
                'partner_invited_at' => $s->partner_invited_at,
                'partner_accepted_at' => $s->partner_accepted_at,
                'rescheduled_from_id' => $s->id,
                'source' => 'reschedule',
                'params' => $s->params,
            ], TherapySession::PAID, $client ?? User::findOrFail($s->client_id), 'free_reschedule');
            // The money moved to the new session: nothing stays retained on the original one.
            $s->forceFill(['balance_refunded' => $s->amount_charged])->save();
            $s->transitionTo('reschedule', $actorId, 'client choice', ['choice_deadline_at' => null], [
                'kind' => 'reschedule', 'new_session_id' => $new->id, 'session_id' => $s->id,
            ], 'client_choice', 'book.session.reschedule_chosen');

            return [$s->fresh(), $new, 0];
        });

        [$s, $new, $amount] = $result;
        if ($new) {
            $this->notify->booked($new, 'Оплата перенесена с отменённой сессии — доплачивать не нужно.');
        } else {
            $this->notify->refundCredited($s, $amount);
        }

        return $new ?? $s;
    }

    /** Pending choices past their deadline become a refund to the balance (book:advance). */
    public function autoResolveChoices(): int
    {
        $count = 0;
        $ids = TherapySession::where('client_choice', 'pending')->whereNotNull('choice_deadline_at')->where('choice_deadline_at', '<=', now())->pluck('id');
        foreach ($ids as $id) {
            $this->choose(TherapySession::findOrFail($id), null, 'refund');
            $count++;
        }

        return $count;
    }

    /** Set the pending choice for a charged session that did not take place by the psychologist's fault. */
    public function requireChoice(TherapySession $s, CarbonImmutable $deadline, ?string $actorId = null): void
    {
        if ($s->client_choice === 'pending' || ! $s->canTransition('pending', 'client_choice')) {
            return;
        }
        $s->transitionTo('pending', $actorId, 'choice required', ['choice_deadline_at' => $deadline], [
            'session_id' => $s->id, 'status' => $s->status, 'deadline' => $deadline->toIso8601String(),
        ], 'client_choice', 'book.session.choice_required');
    }

    public function qualityIncident(TherapySession $s, string $kind): void
    {
        QualityIncident::create(['psychologist_id' => $s->psychologist_id, 'therapy_session_id' => $s->id, 'kind' => $kind]);
        $count = QualityIncident::where('psychologist_id', $s->psychologist_id)->where('created_at', '>=', now()->subDays(30))->count();
        $threshold = Settings::int('P-QUALITY-INCIDENT-THRESHOLD');
        if ($count === $threshold) {
            $p = Psychologist::withTrashed()->find($s->psychologist_id);
            Outbox::record('book.quality.threshold_reached', $p, ['psychologist_id' => $s->psychologist_id, 'count' => $count]);
            DB::afterCommit(fn () => $this->notify->qualityThreshold($p, $count));
        }
    }

    private function lockUpcoming(TherapySession $session): TherapySession
    {
        $s = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
        if (! in_array($s->status, [TherapySession::BOOKED, TherapySession::PAID], true)) {
            BookingError::fail('Сессию в этом статусе нельзя отменить.', 'invalid_status', 'session', 409);
        }
        if (CarbonImmutable::parse($s->starts_at) <= now()) {
            BookingError::fail('Сессия уже началась: отмена недоступна.', 'already_started', 'session', 409);
        }

        return $s;
    }

    private function cancelChargeTask(TherapySession $s, ?string $actorId, bool $force = false): void
    {
        $task = ChargeTask::where('therapy_session_id', $s->id)->first();
        if (! $task) {
            return;
        }
        if ($force && $task->status === 'in_progress') {
            // A blocked participant: the running attempt is let finish; a late success is refunded automatically.
            return;
        }
        $this->charges->cancel($task, 'session cancelled', $actorId);
    }

    /** A free cancel returns the promo use and keeps the corporate limit. */
    private function releaseBenefits(TherapySession $s): void
    {
        if ($s->hasPromo()) {
            $this->promo->restore($s);
        }
        if ($s->isCorporate()) {
            $this->coverage->release($s);
        }
    }
}

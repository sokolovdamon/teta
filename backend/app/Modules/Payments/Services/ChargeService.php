<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Services\BookingNotifications;
use App\Modules\Booking\Services\SessionPayments;
use App\Modules\Booking\Support\SessionTime;
use App\Modules\Notifications\Notifier;
use App\Modules\Payments\Models\ChargeAttempt;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Promo\Contracts\PromoCodes;
use App\Support\Money;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ST-02 / SEQ-02: the charge task is the source of truth for an autocharge; the scheduler only triggers it.
 *
 *  - due at start − P-CHARGE-OFFSET, deadline at start − P-CHARGE-DEADLINE (both fixed when booked/rescheduled);
 *  - first attempt reserves the client balance first (Q-52), the rest is charged by card token with the key {task}:{attempt};
 *  - declines: retry per P-CHARGE-RETRY ("retry"), or wait for the client to pay with another card (no_retry, new_card);
 *  - unknown result: the status is queried before any further attempt — never a second charge;
 *  - deadline: failed_final → balance reservation reversed, session cancelled by the system without retention.
 */
class ChargeService
{
    public function __construct(
        private PaymentService $payments,
        private BalanceService $balance,
        private CardService $cards,
        private SessionPayments $sessionPayments,
        private PromoCodes $promo,
        private Notifier $notifier,
    ) {}

    /** Called when a session is booked earlier than P-CHARGE-OFFSET before the start (inside the booking transaction). */
    public function schedule(TherapySession $session, ?string $actorId = null): ?ChargeTask
    {
        if ($session->amount_due <= 0) {
            return null;
        }
        $task = new ChargeTask;
        $task->forceFill([
            'therapy_session_id' => $session->id,
            'user_id' => $session->client_id,
            'amount' => $session->amount_due,
            'due_at' => $session->charge_due_at,
            'deadline_at' => $session->charge_deadline_at,
            'status' => 'scheduled',
        ])->save();
        $task->recordInitialState($actorId, ['session_id' => $session->id, 'amount' => $task->amount, 'due_at' => $task->due_at->toIso8601String()]);

        return $task;
    }

    /** Reschedule before the charge: due and deadline are recomputed in the same transaction (BR-CANC-02). */
    public function recompute(ChargeTask $task, TherapySession $session, ?string $actorId = null): void
    {
        $task->transitionTo('scheduled', $actorId, 'session rescheduled', [
            'due_at' => $session->charge_due_at,
            'deadline_at' => $session->charge_deadline_at,
        ], ['session_id' => $session->id, 'due_at' => $session->charge_due_at?->toIso8601String()], event: 'pay.charge.rescheduled');
    }

    /** Session cancelled before a successful charge: the task is cancelled and the balance reservation reversed. */
    public function cancel(ChargeTask $task, string $reason, ?string $actorId = null): void
    {
        DB::transaction(function () use ($task, $reason, $actorId) {
            $task = ChargeTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if (! in_array($task->status, ['scheduled', 'retry_wait'], true)) {
                if ($task->status === 'in_progress') {
                    throw ValidationException::withMessages(['session' => 'Сейчас идёт списание оплаты. Попробуйте через минуту.']);
                }

                return;
            }
            $task->transitionTo('cancelled', $actorId, $reason, ['next_attempt_at' => null], ['session_id' => $task->therapy_session_id]);
            $this->releaseReservation($task, 'session cancelled');
        });
    }

    /**
     * Scheduler entry point (pay:dispatch-due-charges, every minute): deadlines first, then due tasks and retries.
     *
     * @return array{failed_final: int, attempted: int}
     */
    public function dispatchDue(): array
    {
        $stats = ['failed_final' => 0, 'attempted' => 0];
        $now = now();

        $expired = ChargeTask::whereIn('status', ['scheduled', 'retry_wait'])->where('deadline_at', '<=', $now)->orderBy('deadline_at')->pluck('id');
        foreach ($expired as $id) {
            $stats['failed_final'] += $this->failFinal($id) ? 1 : 0;
        }

        $due = ChargeTask::where(fn ($q) => $q->where('status', 'scheduled')->where('due_at', '<=', $now))
            ->orWhere(fn ($q) => $q->where('status', 'retry_wait')->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', $now))
            ->orderBy('due_at')->pluck('id');
        foreach ($due as $id) {
            try {
                $this->attempt($id);
                $stats['attempted']++;
            } catch (Throwable $e) {
                Log::error('Charge attempt failed', ['task' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    /** One charge attempt (row-locked phase, gateway call outside the transaction, result applied exactly once). */
    public function attempt(string $taskId): void
    {
        $plan = DB::transaction(function () use ($taskId) {
            $task = ChargeTask::whereKey($taskId)->lock('for update skip locked')->first();
            if (! $task || ! in_array($task->status, ['scheduled', 'retry_wait'], true)) {
                return null;
            }
            if ($task->status === 'scheduled' && $task->due_at > now()) {
                return null;
            }
            if ($task->status === 'retry_wait' && ($task->next_attempt_at === null || $task->next_attempt_at > now())) {
                return null;
            }
            $session = TherapySession::whereKey($task->therapy_session_id)->lockForUpdate()->first();
            if (! $session || $session->status !== TherapySession::BOOKED) {
                $task->transitionTo('cancelled', reason: 'session is not awaiting payment');
                $this->releaseReservation($task, 'session is not awaiting payment');

                return null;
            }
            if ($this->payerCheckoutOpen($task)) {
                return null;
            }
            // Unknown result of the previous attempt: ask the gateway first, never charge twice.
            $previous = $task->payment_id ? Payment::find($task->payment_id) : null;
            if ($previous && in_array($previous->status, ['unknown', 'created'], true)) {
                return ['resolve' => $previous->id];
            }

            $number = $task->attempts + 1;
            $task->transitionTo('in_progress', reason: 'attempt '.$number, attributes: [
                'attempts' => $number,
                'locked_at' => now(),
                'last_attempt_at' => now(),
                'next_attempt_at' => null,
            ], context: ['attempt' => $number, 'session_id' => $session->id]);

            if ($task->balance_operation_id === null && $number === 1) {
                $op = $this->balance->reserveSpend($task->user_id, $task->amount, $session);
                if ($op) {
                    $task->forceFill(['balance_operation_id' => $op->id, 'balance_part' => $op->amount])->save();
                }
            }

            $remaining = $task->amount - $task->balance_part;
            if ($remaining <= 0) {
                $this->succeed($task, $session, null);

                return null;
            }

            $card = $this->cards->defaultCard($task->user_id);
            if (! $card) {
                $this->logAttempt($task, null, 'declined', 'new_card', 'no_card');
                $task->transitionTo('retry_wait', reason: 'no card', attributes: [
                    'locked_at' => null, 'last_error_category' => 'new_card', 'last_error_code' => 'no_card', 'next_attempt_at' => null,
                ], context: ['error_category' => 'new_card', 'session_id' => $session->id]);
                $this->notifyFailure($task, $session, 'no_card');

                return null;
            }

            $client = User::find($task->user_id);
            $payment = $this->payments->createPayment(
                $client,
                'session',
                $session,
                $remaining,
                'Оплата сессии '.SessionTime::format($session->starts_at, $session->client_timezone),
                ReceiptService::forSession($remaining, 'Психологическая консультация, '.SessionTime::formatLabel($session->format), $client?->email),
                ['charge_task_id' => $task->id, 'attempt' => $number],
                false,
                $card,
                $task->id.':'.$number,
            );
            $task->forceFill(['payment_id' => $payment->id])->save();

            return ['charge' => $payment->id, 'card' => $card->id];
        });

        if ($plan === null) {
            return;
        }
        if (isset($plan['resolve'])) {
            $this->payments->resolve(Payment::findOrFail($plan['resolve']));
            $task = ChargeTask::find($taskId);
            if ($task && $task->status === 'retry_wait' && Payment::find($plan['resolve'])?->status !== 'succeeded') {
                // The previous operation did not go through: make a new attempt right away.
                $task->forceFill(['next_attempt_at' => now()])->save();
                $this->attempt($taskId);
            }

            return;
        }

        $payment = Payment::findOrFail($plan['charge']);
        $card = PaymentMethod::findOrFail($plan['card']);
        $this->payments->chargeSaved($payment, $card);
    }

    /** P-CHARGE-DEADLINE without payment: failed_final, reversal, session cancelled without retention. */
    public function failFinal(string $taskId): bool
    {
        return DB::transaction(function () use ($taskId) {
            $task = ChargeTask::whereKey($taskId)->lockForUpdate()->first();
            if (! $task || ! in_array($task->status, ['scheduled', 'retry_wait', 'in_progress'], true)) {
                return false;
            }
            $task->transitionTo('failed_final', reason: 'charge deadline', attributes: ['next_attempt_at' => null, 'locked_at' => null], context: [
                'session_id' => $task->therapy_session_id,
                'amount' => $task->amount,
                'attempts' => $task->attempts,
                'last_error_category' => $task->last_error_category,
            ]);
            $this->releaseReservation($task, 'charge deadline');

            $session = TherapySession::whereKey($task->therapy_session_id)->lockForUpdate()->first();
            if ($session && $session->status === TherapySession::BOOKED) {
                $session->transitionTo(TherapySession::CANCELLED_BY_SYSTEM, reason: 'Оплата не прошла к крайнему сроку', attributes: [
                    'cancelled_at' => now(),
                    'cancel_kind' => 'charge_failed',
                    'cancel_reason' => 'Оплата не прошла к крайнему сроку',
                ], context: ['kind' => 'charge_failed', 'price' => $session->price, 'amount_charged' => 0]);
                if ($session->hasPromo()) {
                    $this->promo->restore($session);
                }
                app(BookingNotifications::class)->cancelledUnpaid($session);
            }

            return true;
        });
    }

    /**
     * pay:recover-stuck-charges: attempts in progress for more than 10 minutes and payer payments with a lost
     * result are resolved by a status query (ST-02 in_progress → in_progress, BR-PAY-13).
     */
    public function recoverStuck(): int
    {
        $count = 0;
        $threshold = now()->subMinutes((int) config('payments.stuck_after_minutes', 10));
        foreach (ChargeTask::where('status', 'in_progress')->where('locked_at', '<=', $threshold)->pluck('id') as $id) {
            $task = ChargeTask::find($id);
            $payment = $task?->payment_id ? Payment::find($task->payment_id) : null;
            if ($payment && in_array($payment->status, ['created', 'unknown'], true)) {
                $payment = $this->payments->resolve($payment);
            }
            $task->refresh();
            if ($task->status === 'in_progress') {
                if (! $payment || ! in_array($payment->status, ['created', 'unknown'], true)) {
                    // Crash between the phases or a final payment without its effect: back to the queue.
                    $task->transitionTo('retry_wait', reason: 'stuck recovered', attributes: ['locked_at' => null, 'next_attempt_at' => now(), 'last_error_category' => 'unknown']);
                } else {
                    $task->transitionTo('in_progress', reason: 'status query', attributes: ['locked_at' => now()], event: 'pay.charge.status_queried');
                }
            }
            $count++;
        }

        $stale = Payment::where('with_payer', true)->whereIn('status', ['requires_3ds', 'unknown'])->where('updated_at', '<=', $threshold)->limit(200)->get();
        foreach ($stale as $payment) {
            $this->payments->resolve($payment);
            $count++;
        }

        return $count;
    }

    /**
     * CL-07: pay a charge that failed with another card (payment with the payer, 3-D Secure).
     * Returns null when the balance alone covered the amount.
     */
    public function payWithPayer(ChargeTask $task, User $client, bool $saveCard = true): ?Payment
    {
        return DB::transaction(function () use ($task, $client, $saveCard) {
            $task = ChargeTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($task->user_id === $client->id, 404);
            if (! in_array($task->status, ['retry_wait', 'scheduled'], true)) {
                throw ValidationException::withMessages(['task' => 'Эту сессию сейчас нельзя оплатить: списание уже выполнено или отменено.']);
            }
            $session = TherapySession::findOrFail($task->therapy_session_id);
            if ($task->payer_payment_id) {
                $open = Payment::find($task->payer_payment_id);
                if ($open && $open->status === 'requires_3ds' && $open->confirmation_url) {
                    return $open;
                }
            }
            if ($task->balance_operation_id === null) {
                $op = $this->balance->reserveSpend($task->user_id, $task->amount, $session);
                if ($op) {
                    $task->forceFill(['balance_operation_id' => $op->id, 'balance_part' => $op->amount])->save();
                }
            }
            $remaining = $task->amount - $task->balance_part;
            if ($remaining <= 0) {
                if ($task->status === 'scheduled') {
                    $task->transitionTo('in_progress', reason: 'paid from balance');
                }
                $this->succeed($task, $session, null);

                return null;
            }
            $payment = $this->payments->startPayerPayment(
                $client,
                'session',
                $session,
                $remaining,
                'Оплата сессии '.SessionTime::format($session->starts_at, $session->client_timezone),
                '/client/payments',
                ReceiptService::forSession($remaining, 'Психологическая консультация, '.SessionTime::formatLabel($session->format), $client->email),
                $saveCard,
                ['charge_task_id' => $task->id, 'payer' => true],
            );
            $task->forceFill(['payer_payment_id' => $payment->id])->save();

            return $payment;
        });
    }

    // ── Purpose "session" callbacks (inside the payment transaction) ───────────────────────────────────────────

    public function onPaymentSucceeded(Payment $payment): void
    {
        $task = ChargeTask::whereKey($payment->meta('charge_task_id'))->lockForUpdate()->first();
        if (! $task) {
            return;
        }
        if ($task->status === 'succeeded' || in_array($task->status, ['cancelled', 'failed_final'], true)) {
            if ($task->payment_id !== $payment->id) {
                // A second successful payment for the same session (payer paid while a retry went through, or after
                // cancellation): it is returned to the card in full — never a double charge (BR-PAY-06).
                DB::afterCommit(fn () => $this->payments->refundToCard($payment->fresh(), $payment->amount, $task, 'duplicate_or_cancelled'));
                $this->notifier->send(User::findOrFail($payment->user_id), 'pay.payment_returned', [
                    'amount' => Money::format($payment->amount),
                ], '/client/payments');
            }

            return;
        }
        if ($task->status === 'scheduled') {
            $task->transitionTo('in_progress', reason: 'payer payment');
        }
        $session = TherapySession::whereKey($task->therapy_session_id)->lockForUpdate()->firstOrFail();
        $this->logAttempt($task, $payment, 'succeeded', null, null);
        $this->succeed($task, $session, $payment);
    }

    public function onPaymentDeclined(Payment $payment): void
    {
        $task = ChargeTask::whereKey($payment->meta('charge_task_id'))->lockForUpdate()->first();
        if (! $task) {
            return;
        }
        $session = TherapySession::find($task->therapy_session_id);
        if ($payment->meta('payer')) {
            // The client's attempt with another card failed: the task keeps waiting until the deadline.
            $task->forceFill(['payer_payment_id' => null])->save();

            return;
        }
        if ($task->payment_id !== $payment->id) {
            return;
        }
        $category = $payment->error_category ?? 'retry';
        $this->logAttempt($task, $payment, 'declined', $category, $payment->error_code);
        $next = $category === 'retry' ? $this->nextAttemptAt($task) : null;
        $attrs = ['locked_at' => null, 'last_error_category' => $category, 'last_error_code' => $payment->error_code, 'next_attempt_at' => $next];
        if ($task->status === 'in_progress') {
            $task->transitionTo('retry_wait', reason: $payment->error_code, attributes: $attrs, context: [
                'error_category' => $category, 'attempt' => $task->attempts, 'session_id' => $task->therapy_session_id,
            ]);
            if ($session) {
                $this->notifyFailure($task, $session, $payment->error_code);
            }
        } else {
            $task->forceFill($attrs)->save();
        }
    }

    public function onPaymentUnknown(Payment $payment): void
    {
        $task = ChargeTask::whereKey($payment->meta('charge_task_id'))->lockForUpdate()->first();
        if (! $task || $payment->meta('payer') || $task->payment_id !== $payment->id || $task->status !== 'in_progress') {
            return;
        }
        $this->logAttempt($task, $payment, 'unknown', 'unknown', 'timeout');
        $task->transitionTo('retry_wait', reason: 'unknown status', attributes: [
            'locked_at' => null,
            'last_error_category' => 'unknown',
            'last_error_code' => 'timeout',
            'next_attempt_at' => now()->addMinutes((int) config('payments.unknown_recheck_minutes', 10)),
        ], context: ['error_category' => 'unknown', 'session_id' => $task->therapy_session_id]);
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────────────────────────

    private function succeed(ChargeTask $task, TherapySession $session, ?Payment $payment): void
    {
        $op = $task->balance_operation_id ? ClientBalanceOperation::find($task->balance_operation_id) : null;
        if ($session->status !== TherapySession::BOOKED) {
            // The session was cancelled while the charge was running: the money is returned in full.
            $task->transitionTo('succeeded', reason: 'session cancelled during the charge', attributes: [
                'payment_id' => $payment?->id ?? $task->payment_id, 'locked_at' => null, 'next_attempt_at' => null,
            ], context: ['session_id' => $session->id, 'returned' => true]);
            if ($op) {
                $this->balance->reverseSpend($op, 'session cancelled during the charge');
            }
            if ($payment) {
                DB::afterCommit(fn () => $this->payments->refundToCard($payment->fresh(), $payment->amount, $task, 'session_cancelled'));
                if ($client = User::find($payment->user_id)) {
                    $this->notifier->send($client, 'pay.payment_returned', ['amount' => Money::format($payment->amount)], '/client/payments');
                }
            }

            return;
        }
        if ($op) {
            $this->balance->confirmSpend($op, $session);
        }
        $task->transitionTo('succeeded', reason: $payment ? 'paid' : 'paid from balance', attributes: [
            'payment_id' => $payment?->id ?? $task->payment_id,
            'locked_at' => null,
            'next_attempt_at' => null,
        ], context: ['session_id' => $session->id, 'amount' => $task->amount, 'balance_part' => $task->balance_part]);

        $card = $payment?->amount ?? 0;
        $balancePart = (int) $task->balance_part;
        $source = $card > 0 ? ($balancePart > 0 ? 'mixed' : 'card') : 'balance';
        if ($this->sessionPayments->markPaid($session, $source, $card, $balancePart, (int) ($op?->certificate_amount ?? 0), $payment)) {
            $client = User::find($session->client_id);
            if ($client) {
                $this->notifier->send($client, 'pay.charge_succeeded', [
                    'date' => SessionTime::format($session->starts_at, $session->client_timezone),
                    'amount' => Money::format($task->amount),
                    'card_part' => Money::format($card),
                    'balance_part' => Money::format($balancePart),
                ], '/client/payments');
            }
        }
    }

    private function releaseReservation(ChargeTask $task, string $reason): void
    {
        if ($task->balance_operation_id && ($op = ClientBalanceOperation::find($task->balance_operation_id))) {
            $this->balance->reverseSpend($op, $reason);
        }
    }

    /** Next retry by P-CHARGE-RETRY (minutes after the first attempt), only before the deadline. */
    private function nextAttemptAt(ChargeTask $task): ?CarbonImmutable
    {
        $offsets = array_values((array) Settings::get('P-CHARGE-RETRY'));
        $index = $task->attempts - 1;
        if (! isset($offsets[$index])) {
            return null;
        }
        $first = CarbonImmutable::parse($task->due_at);
        $at = $first->addMinutes((int) $offsets[$index]);
        if ($at < now()) {
            $at = CarbonImmutable::now();
        }

        return $at < $task->deadline_at ? $at : null;
    }

    private function payerCheckoutOpen(ChargeTask $task): bool
    {
        if (! $task->payer_payment_id) {
            return false;
        }
        $payment = Payment::find($task->payer_payment_id);

        return $payment
            && in_array($payment->status, ['created', 'requires_3ds', 'unknown'], true)
            && $payment->created_at > now()->subMinutes((int) config('payments.payer_checkout_ttl_minutes', 30));
    }

    private function logAttempt(ChargeTask $task, ?Payment $payment, string $result, ?string $category, ?string $code): void
    {
        ChargeAttempt::create([
            'charge_task_id' => $task->id,
            'payment_id' => $payment?->id,
            'number' => max(1, $task->attempts),
            'result' => $result,
            'error_category' => $category,
            'error_code' => $code,
        ]);
    }

    private function notifyFailure(ChargeTask $task, TherapySession $session, ?string $code): void
    {
        $client = User::find($task->user_id);
        if (! $client) {
            return;
        }
        $vars = [
            'date' => SessionTime::format($session->starts_at, $session->client_timezone),
            'deadline' => SessionTime::format($task->deadline_at, $session->client_timezone),
            'amount' => Money::format($task->amount),
            'reason' => PaymentService::declineMessage($code),
        ];
        $this->notifier->send($client, $code === 'no_card' ? 'pay.charge_no_card' : 'pay.charge_failed', $vars, '/client/payments?pay='.$task->id);
    }
}

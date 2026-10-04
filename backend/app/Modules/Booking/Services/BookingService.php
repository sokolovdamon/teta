<?php

namespace App\Modules\Booking\Services;

use App\Models\User;
use App\Modules\Booking\Contracts\CorporateCoverage;
use App\Modules\Booking\Models\BookingIntent;
use App\Modules\Booking\Models\SessionTimeRequest;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Support\BookingError;
use App\Modules\Booking\Support\SessionTime;
use App\Modules\Notifications\Notifier;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\BalanceService;
use App\Modules\Payments\Services\CardService;
use App\Modules\Payments\Services\ChargeService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\ReceiptService;
use App\Modules\Promo\Contracts\PromoCodes;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Schedule\Models\SlotHold;
use App\Modules\Schedule\Services\SlotService;
use App\Support\Events\Outbox;
use App\Support\Money;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * BOOK, ST-01, SEQ-01 and SEQ-03: quote, slot holds, booking in three payment modes and rescheduling.
 *
 *  - deferred  — the start is later than P-CHARGE-OFFSET: the session is "booked", a charge task is scheduled;
 *  - immediate — later booking: pay now, balance first then card (Q-52); if the payment fails the booking is not
 *                created (BR-BOOK-04); without a saved card the payer pays in the checkout while the slot is held;
 *  - corporate — covered by the client's corporate program (CorporateCoverage): "paid" by the company.
 * The price is fixed at booking (BR-BOOK-03); a promo code is quoted, reserved, consumed on charge and restored
 * on a cancel before the charge (PromoCodes contract).
 */
class BookingService
{
    public const PARAM_SNAPSHOT = [
        'P-CHARGE-OFFSET', 'P-CHARGE-DEADLINE', 'P-LATE-CANCEL-REFUND', 'P-LATE-RESCHEDULE-LIMIT',
        'P-NOSHOW-WAIT', 'P-AUTO-COMPLETE-MIN', 'P-OUTCOME-DEADLINE', 'P-COMMISSION',
    ];

    public function __construct(
        private SlotService $slots,
        private PromoCodes $promo,
        private CorporateCoverage $coverage,
        private BalanceService $balance,
        private CardService $cards,
        private ChargeService $charges,
        private PaymentService $payments,
        private SessionPayments $sessionPayments,
        private BookingNotifications $notify,
        private Notifier $notifier,
    ) {}

    // ── Quote ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function quote(?User $client, Psychologist $p, string $format, CarbonImmutable $start, ?string $promoCode = null): array
    {
        $price = $p->priceFor($format);
        if (! $price || ($format === 'pair' && ! $p->works_pair) || ($format === 'individual' && $p->works_individual === false)) {
            throw ValidationException::withMessages(['format' => 'Психолог не проводит сессии в этом формате.']);
        }
        $start = $start->utc()->startOfMinute();
        $corporate = $client ? $this->coverage->coverFor($client, $format) : null;

        $discount = 0;
        $appliedCode = null;
        $promoId = null;
        if ($promoCode && ! $corporate) {
            if (! $client) {
                throw ValidationException::withMessages(['promo_code' => 'Войдите, чтобы применить промокод.']);
            }
            $q = $this->promo->quote($promoCode, $client, $p, $format, $price);
            $discount = max(0, min($price, (int) $q['discount']));
            $appliedCode = $q['code'];
            $promoId = $q['promo_code_id'] ?? null;
        }
        $amountDue = $price - $discount;
        $offset = Settings::int('P-CHARGE-OFFSET');
        $chargeAt = $start->subMinutes($offset);
        $deadlineAt = $start->subMinutes(Settings::int('P-CHARGE-DEADLINE'));
        $mode = $corporate ? 'corporate' : ($chargeAt->greaterThan(now()) ? 'deferred' : 'immediate');
        $balance = $client ? $this->balance->summary($client->id)['available'] : 0;
        $hasCard = $client && $this->cards->defaultCard($client->id) !== null;
        $balanceToUse = $mode === 'corporate' ? 0 : min($balance, $amountDue);
        $duration = $this->slots->duration($format);

        return [
            'psychologist_id' => $p->id,
            'format' => $format,
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->addMinutes($duration)->toIso8601String(),
            'duration_min' => $duration,
            'price' => $price,
            'discount' => $discount,
            'promo_code' => $appliedCode,
            'promo_code_id' => $promoId,
            'amount_due' => $mode === 'corporate' ? 0 : $amountDue,
            'payment_mode' => $mode,
            'charge_at' => $mode === 'deferred' ? $chargeAt->toIso8601String() : null,
            'charge_deadline_at' => $mode === 'deferred' ? $deadlineAt->toIso8601String() : null,
            'free_cancel_until' => $mode === 'deferred' || $mode === 'corporate' ? $chargeAt->toIso8601String() : null,
            'balance_available' => $balance,
            'balance_to_use' => $balanceToUse,
            'card_to_pay' => $mode === 'corporate' ? 0 : $amountDue - $balanceToUse,
            'corporate' => $corporate ? ['participation_id' => $corporate->id, 'program' => $corporate->program?->title] : null,
            'requirements' => [
                'authenticated' => $client !== null,
                'email_verified' => (bool) $client?->email_verified_at,
                'adult' => (bool) $client?->isAdult(),
                'card_bound' => $hasCard,
                'card_required' => $mode === 'deferred' && $amountDue > 0,
            ],
            'rules' => $this->rulesText($mode, $chargeAt),
        ];
    }

    // ── Holds ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function hold(?User $user, Psychologist $p, string $format, CarbonImmutable $start, ?string $guestToken = null): SlotHold
    {
        $start = $start->utc()->startOfMinute();

        return DB::transaction(function () use ($user, $p, $format, $start, $guestToken) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$p->id]);
            $own = SlotHold::where('psychologist_id', $p->id)->where('starts_at', $start)->where('format', $format)->where('expires_at', '>', now())
                ->where(fn ($q) => $user ? $q->where('user_id', $user->id) : $q->where('guest_token', $guestToken ?? '-'))
                ->first();
            if ($own) {
                $own->forceFill(['expires_at' => now()->addMinutes(Settings::int('P-SLOT-HOLD'))])->save();

                return $own;
            }

            return $this->slots->hold($p, $start, $format, $user, $guestToken);
        });
    }

    public function releaseHold(SlotHold $hold, ?User $user, ?string $guestToken): void
    {
        $owns = ($user && $hold->user_id === $user->id) || ($hold->guest_token && $guestToken && hash_equals($hold->guest_token, $guestToken));
        abort_unless($owns, 404);
        $this->slots->release($hold);
    }

    // ── Booking ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data  psychologist_id, format, starts_at, promo_code?, hold_id?, guest_token?,
     *                                      client_request_ids?, partner_email?, source?, pay_with? (saved_card|new_card), idempotency_key?
     * @return array{session?: TherapySession, intent?: BookingIntent, confirmation_url?: string|null}
     */
    public function book(User $client, array $data): array
    {
        $this->assertCanBook($client);
        $p = Psychologist::findOrFail($data['psychologist_id']);
        $format = $data['format'] ?? 'individual';
        $start = CarbonImmutable::parse($data['starts_at'])->utc()->startOfMinute();
        $key = $data['idempotency_key'] ?? null;

        if ($key) {
            if ($existing = TherapySession::where('client_id', $client->id)->where('idempotency_key', $key)->first()) {
                return ['session' => $existing];
            }
            if ($intent = BookingIntent::where('client_id', $client->id)->where('idempotency_key', $key)->first()) {
                return $this->intentResult($intent);
            }
        }

        $partnerEmail = $format === 'pair' && ! empty($data['partner_email']) ? mb_strtolower(trim((string) $data['partner_email'])) : null;
        if ($partnerEmail && $partnerEmail === mb_strtolower($client->email)) {
            throw ValidationException::withMessages(['partner_email' => 'Укажите email второго участника, а не свой.']);
        }

        $quote = $this->quote($client, $p, $format, $start, $data['promo_code'] ?? null);
        $mode = $quote['payment_mode'];
        if ($mode === 'deferred' && $quote['amount_due'] > 0 && ! $quote['requirements']['card_bound']) {
            BookingError::fail('Привяжите карту: оплата спишется автоматически за 12 часов до начала сессии.', 'card_required', 'card');
        }

        $plan = DB::transaction(function () use ($client, $p, $format, $start, $data, $quote, $mode, $partnerEmail, $key) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$p->id]);
            $hold = $this->findHold($client, $p, $start, $format, $data['hold_id'] ?? null, $data['guest_token'] ?? null);
            $this->slots->assertBookable($p, $start, $format, ['ignore_hold_id' => $hold?->id]);
            $this->assertLimits($client, $p, $start, $format);

            $attrs = [
                'client_id' => $client->id,
                'psychologist_id' => $p->id,
                'format' => $format,
                'starts_at' => $start,
                'ends_at' => CarbonImmutable::parse($quote['ends_at']),
                'duration_min' => $quote['duration_min'],
                'price' => $quote['price'],
                'discount' => $quote['discount'],
                'amount_due' => $quote['amount_due'],
                'promo_code_id' => $quote['promo_code_id'],
                'charge_due_at' => $start->subMinutes(Settings::int('P-CHARGE-OFFSET')),
                'charge_deadline_at' => $start->subMinutes(Settings::int('P-CHARGE-DEADLINE')),
                'client_timezone' => $client->timezone ?: config('platform.timezone'),
                'client_request_ids' => array_values(array_unique(array_map('strval', (array) ($data['client_request_ids'] ?? [])))) ?: null,
                'partner_email' => $partnerEmail,
                'partner_invited_at' => $partnerEmail ? now() : null,
                'source' => $data['source'] ?? 'catalog',
                'params' => $this->paramSnapshot(),
                'idempotency_key' => $key,
            ];

            if ($mode === 'corporate') {
                $participation = $this->coverage->coverFor($client, $format);
                $session = $this->createSession([...$attrs, 'corporate_participation_id' => $participation?->id, 'discount' => 0, 'amount_due' => 0, 'payment_source' => 'corporate', 'paid_at' => now()], TherapySession::PAID, $client, 'corporate');
                $this->coverage->consume($session);
                $hold?->delete();

                return ['session' => $session, 'note' => 'Сессию оплачивает компания по корпоративной программе.'];
            }

            if ($mode === 'deferred' || $quote['amount_due'] === 0) {
                $status = $mode === 'deferred' ? TherapySession::BOOKED : TherapySession::PAID;
                $session = $this->createSession([
                    ...$attrs,
                    'payment_source' => $status === TherapySession::PAID ? 'free' : null,
                    'paid_at' => $status === TherapySession::PAID ? now() : null,
                ], $status, $client, $mode);
                $this->reservePromo($quote, $client, $session, consume: $status === TherapySession::PAID);
                $this->charges->schedule($session, $client->id);
                $hold?->delete();

                return ['session' => $session, 'note' => $this->deferredNote($session)];
            }

            // Immediate payment: the cabinet balance first (Q-52).
            if ($this->balance->summary($client->id)['available'] >= $quote['amount_due']) {
                $session = $this->createSession([
                    ...$attrs,
                    'payment_source' => 'balance',
                    'paid_at' => now(),
                    'paid_balance' => $quote['amount_due'],
                    'amount_charged' => $quote['amount_due'],
                ], TherapySession::PAID, $client, $mode);
                $op = $this->balance->reserveSpend($client->id, $quote['amount_due'], $session);
                if (! $op || $op->amount < $quote['amount_due']) {
                    BookingError::fail('Баланс личного кабинета изменился. Повторите запись.', 'balance_changed', 'booking', 409);
                }
                $this->balance->confirmSpend($op, $session);
                $session->forceFill(['paid_certificate' => (int) $op->certificate_amount])->save();
                $this->reservePromo($quote, $client, $session, consume: true);
                $hold?->delete();

                return ['session' => $session, 'note' => 'Оплата '.Money::format($quote['amount_due']).' списана с баланса личного кабинета.'];
            }

            // The rest is paid by card; the session is created only after a successful payment (BR-BOOK-04).
            $hold = $this->ensureHold($hold, $client, $p, $start, $format);
            $intent = BookingIntent::create([
                'client_id' => $client->id,
                'psychologist_id' => $p->id,
                'slot_hold_id' => $hold->id,
                'format' => $format,
                'starts_at' => $start,
                'ends_at' => $attrs['ends_at'],
                'duration_min' => $attrs['duration_min'],
                'price' => $quote['price'],
                'discount' => $quote['discount'],
                'amount_due' => $quote['amount_due'],
                'promo_code' => $quote['promo_code'],
                'data' => array_intersect_key($attrs, array_flip(['client_timezone', 'client_request_ids', 'partner_email', 'source', 'params', 'promo_code_id'])),
                'status' => BookingIntent::PENDING,
                'idempotency_key' => $key,
                'expires_at' => $hold->expires_at,
            ]);
            $op = $this->balance->reserveSpend($client->id, $quote['amount_due'], $intent, 'booking_payment');
            if ($op) {
                $intent->forceFill(['balance_operation_id' => $op->id, 'balance_part' => $op->amount])->save();
            }
            $remaining = $quote['amount_due'] - $intent->balance_part;
            $card = ($data['pay_with'] ?? 'saved_card') === 'new_card' ? null : $this->cards->defaultCard($client->id);
            $payment = $this->payments->createPayment(
                $client,
                'booking',
                $intent,
                $remaining,
                'Оплата сессии '.SessionTime::format($start, $attrs['client_timezone']),
                ReceiptService::forSession($remaining, 'Психологическая консультация, '.SessionTime::formatLabel($format), $client->email),
                ['booking_intent_id' => $intent->id, 'save_card' => $card === null, 'next' => '/client/sessions'],
                $card === null,
                $card,
            );
            $intent->forceFill(['payment_id' => $payment->id])->save();

            return ['intent' => $intent, 'payment' => $payment, 'card' => $card];
        });

        if (isset($plan['session'])) {
            $this->afterBooked($plan['session'], $plan['note']);

            return ['session' => $plan['session']];
        }

        /** @var Payment $payment */
        $payment = $plan['payment'];
        /** @var PaymentMethod|null $card */
        $card = $plan['card'];
        if ($card === null) {
            $payment = $this->payments->beginCheckout($payment);

            return ['intent' => $plan['intent']->fresh(), 'confirmation_url' => $payment->confirmation_url];
        }

        $payment = $this->payments->chargeSaved($payment, $card);
        $intent = $plan['intent']->fresh();
        if ($intent->status === BookingIntent::FAILED) {
            BookingError::fail(
                'Оплата не прошла. '.($payment->error_message ?? 'Банк отклонил оплату.').' Запись не создана — оплатите другой картой.',
                'payment_declined',
                'payment',
                extra: ['intent_id' => $intent->id, 'error_category' => $payment->error_category],
            );
        }

        return $this->intentResult($intent);
    }

    /** @return array{session?: TherapySession, intent?: BookingIntent, confirmation_url?: string|null} */
    public function intentResult(BookingIntent $intent): array
    {
        if ($intent->status === BookingIntent::COMPLETED && $intent->therapy_session_id) {
            return ['session' => TherapySession::findOrFail($intent->therapy_session_id), 'intent' => $intent];
        }
        $payment = $intent->payment_id ? Payment::find($intent->payment_id) : null;

        return ['intent' => $intent, 'confirmation_url' => $payment?->status === 'requires_3ds' ? $payment->confirmation_url : null];
    }

    /** Purpose "booking": the immediate payment succeeded — create the session as paid (inside the payment transaction). */
    public function completeIntent(Payment $payment): void
    {
        $intent = BookingIntent::whereKey($payment->meta('booking_intent_id'))->lockForUpdate()->first();
        if (! $intent) {
            return;
        }
        if ($intent->status === BookingIntent::COMPLETED) {
            if ($intent->payment_id !== $payment->id) {
                $this->returnPayment($payment, $intent);
            }

            return;
        }
        $p = Psychologist::withTrashed()->findOrFail($intent->psychologist_id);
        DB::select('select pg_advisory_xact_lock(hashtext(?))', [$p->id]);
        $free = $p->isBookable() && $this->slots->isAvailable($p, CarbonImmutable::parse($intent->starts_at), $intent->format, ['ignore_hold_id' => $intent->slot_hold_id, 'ignore_lead' => true]);
        $op = $intent->balance_operation_id ? ClientBalanceOperation::find($intent->balance_operation_id) : null;
        if (! $free) {
            // The slot was lost while the payer was paying: nothing is kept, the payment goes back to the card.
            $intent->forceFill(['status' => BookingIntent::FAILED, 'failure_reason' => 'slot_taken', 'payment_id' => $payment->id])->save();
            if ($op) {
                $this->balance->reverseSpend($op, 'slot taken');
            }
            $this->returnPayment($payment, $intent);

            return;
        }

        $client = User::findOrFail($intent->client_id);
        $data = $intent->data ?? [];
        $balancePart = (int) $intent->balance_part;
        $session = $this->createSession([
            'client_id' => $client->id,
            'psychologist_id' => $p->id,
            'format' => $intent->format,
            'starts_at' => $intent->starts_at,
            'ends_at' => $intent->ends_at,
            'duration_min' => $intent->duration_min,
            'price' => $intent->price,
            'discount' => $intent->discount,
            'amount_due' => $intent->amount_due,
            'promo_code_id' => $data['promo_code_id'] ?? null,
            'charge_due_at' => CarbonImmutable::parse($intent->starts_at)->subMinutes(Settings::int('P-CHARGE-OFFSET')),
            'charge_deadline_at' => CarbonImmutable::parse($intent->starts_at)->subMinutes(Settings::int('P-CHARGE-DEADLINE')),
            'client_timezone' => $data['client_timezone'] ?? $client->timezone,
            'client_request_ids' => $data['client_request_ids'] ?? null,
            'partner_email' => $data['partner_email'] ?? null,
            'partner_invited_at' => ! empty($data['partner_email']) ? now() : null,
            'source' => $data['source'] ?? 'catalog',
            'params' => $data['params'] ?? $this->paramSnapshot(),
            'idempotency_key' => $intent->idempotency_key,
            'payment_source' => $balancePart > 0 ? 'mixed' : 'card',
            'payment_id' => $payment->id,
            'paid_at' => now(),
            'paid_card' => $payment->amount,
            'paid_balance' => $balancePart,
            'paid_certificate' => (int) ($op?->certificate_amount ?? 0),
            'amount_charged' => $payment->amount + $balancePart,
        ], TherapySession::PAID, $client, 'immediate');
        if ($op) {
            $this->balance->confirmSpend($op, $session);
        }
        $intent->forceFill(['status' => BookingIntent::COMPLETED, 'therapy_session_id' => $session->id, 'payment_id' => $payment->id])->save();
        SlotHold::whereKey($intent->slot_hold_id)->delete();
        if ($intent->promo_code) {
            try {
                $this->promo->reserve($intent->promo_code, $client, $session);
                $this->promo->consume($session);
            } catch (Throwable $e) {
                Log::warning('Promo code could not be reserved after payment', ['session' => $session->id, 'error' => $e->getMessage()]);
            }
        }
        DB::afterCommit(fn () => $this->afterBooked($session->fresh(), 'Оплата '.Money::format($intent->amount_due).' получена.'));
    }

    /** Purpose "booking": the payment was declined — the booking is not created, the balance reservation is reversed. */
    public function failIntent(Payment $payment): void
    {
        $intent = BookingIntent::whereKey($payment->meta('booking_intent_id'))->lockForUpdate()->first();
        if (! $intent || $intent->status !== BookingIntent::PENDING || $intent->payment_id !== $payment->id) {
            return;
        }
        $intent->forceFill(['status' => BookingIntent::FAILED, 'failure_reason' => $payment->error_code ?? 'declined'])->save();
        if ($intent->balance_operation_id && ($op = ClientBalanceOperation::find($intent->balance_operation_id))) {
            $this->balance->reverseSpend($op, 'payment declined');
        }
    }

    /** Pending intents whose hold has expired (book:advance). */
    public function expireIntents(): int
    {
        $count = 0;
        foreach (BookingIntent::where('status', BookingIntent::PENDING)->where('expires_at', '<=', now()->subMinutes(5))->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$count) {
                $intent = BookingIntent::whereKey($id)->lockForUpdate()->first();
                if (! $intent || $intent->status !== BookingIntent::PENDING) {
                    return;
                }
                $payment = $intent->payment_id ? Payment::find($intent->payment_id) : null;
                if ($payment && in_array($payment->status, ['created', 'requires_3ds', 'unknown'], true)) {
                    // Give the payment a chance to settle first; a late success is handled by completeIntent().
                    if ($payment->updated_at > now()->subMinutes(30)) {
                        return;
                    }
                }
                $intent->forceFill(['status' => BookingIntent::EXPIRED, 'failure_reason' => 'expired'])->save();
                if ($intent->balance_operation_id && ($op = ClientBalanceOperation::find($intent->balance_operation_id))) {
                    $this->balance->reverseSpend($op, 'booking expired');
                }
                $count++;
            });
        }

        return $count;
    }

    // ── Reschedule (SEQ-03, DEC-56) ───────────────────────────────────────────────────────────────────────────

    /** Has the money for this session already been taken (charge time passed for corporate sessions)? */
    public function isCharged(TherapySession $s): bool
    {
        if ($s->isCorporate()) {
            return now() >= CarbonImmutable::parse($s->starts_at)->subMinutes((int) $s->param('P-CHARGE-OFFSET'));
        }

        return $s->status === TherapySession::PAID || $s->paid_at !== null;
    }

    public function reschedule(TherapySession $session, User $actor, CarbonImmutable $newStart, string $by, ?string $reason = null): TherapySession
    {
        $newStart = $newStart->utc()->startOfMinute();
        [$session, $old, $late] = DB::transaction(function () use ($session, $actor, $newStart, $by, $reason) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$session->psychologist_id]);
            $s = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (! in_array($s->status, [TherapySession::BOOKED, TherapySession::PAID], true)) {
                BookingError::fail('Сессию в этом статусе нельзя перенести.', 'invalid_status', 'starts_at', 409);
            }
            if (CarbonImmutable::parse($s->starts_at) <= now()) {
                BookingError::fail('Сессия уже началась: перенос недоступен.', 'already_started', 'starts_at', 409);
            }
            if ($newStart->equalTo(CarbonImmutable::parse($s->starts_at))) {
                throw ValidationException::withMessages(['starts_at' => 'Выберите другое время.']);
            }
            $late = $this->isCharged($s);
            $task = ChargeTask::where('therapy_session_id', $s->id)->first();
            if ($late) {
                $minStart = now()->addMinutes((int) $s->param('P-CHARGE-OFFSET'));
                if ($newStart < $minStart) {
                    BookingError::fail('После списания оплаты перенести сессию можно на время не раньше чем через 12 часов от текущего момента.', 'late_reschedule_too_soon', 'starts_at', extra: ['min_start' => $minStart->toIso8601String()]);
                }
                if ($by === 'client' && $s->late_reschedule_count >= (int) $s->param('P-LATE-RESCHEDULE-LIMIT')) {
                    BookingError::fail('Лимит переносов после списания оплаты исчерпан.', 'late_reschedule_limit', 'starts_at');
                }
            } elseif ($task && $task->status !== 'scheduled') {
                $message = $task->status === 'in_progress' ? 'Сейчас идёт списание оплаты. Попробуйте через минуту.' : 'Списание оплаты не прошло: сначала оплатите сессию или отмените её.';
                BookingError::fail($message, 'charge_pending', 'starts_at', 409);
            }

            $p = Psychologist::withTrashed()->findOrFail($s->psychologist_id);
            $this->slots->assertBookable($p, $newStart, $s->format, ['ignore_session_id' => $s->id]);
            $old = CarbonImmutable::parse($s->starts_at);
            $attrs = [
                'starts_at' => $newStart,
                'ends_at' => $newStart->addMinutes((int) $s->duration_min),
                'reschedule_count' => $s->reschedule_count + 1,
                'reminders_sent' => ReminderService::passedThresholds($newStart),
            ];
            if ($late) {
                $attrs['late_reschedule_count'] = $s->late_reschedule_count + 1;
            } else {
                $due = $newStart->subMinutes((int) $s->param('P-CHARGE-OFFSET'));
                $attrs['charge_due_at'] = $due->max(now());
                $attrs['charge_deadline_at'] = $newStart->subMinutes((int) $s->param('P-CHARGE-DEADLINE'));
            }
            $s->transitionTo($s->status, $actor->id, $reason, $attrs, [
                'kind' => $late ? 'late_reschedule' : 'reschedule',
                'by' => $by,
                'from_starts_at' => $old->toIso8601String(),
                'to_starts_at' => $newStart->toIso8601String(),
                'price' => $s->price,
                'amount_charged' => (int) $s->amount_charged,
            ], event: 'book.session.rescheduled');
            if (! $late && $task) {
                $this->charges->recompute($task, $s, $actor->id);
            }

            return [$s, $old, $late];
        });

        $note = $late || $session->status === TherapySession::PAID
            ? 'Оплата переходит на новое время.'
            : $this->deferredNote($session);
        $this->notify->rescheduled($session, $old, $note);

        return $session;
    }

    /** Free slots for a reschedule of this session (same psychologist and format; DEC-56 after the charge). */
    public function rescheduleSlots(TherapySession $s, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $p = Psychologist::withTrashed()->findOrFail($s->psychologist_id);
        $late = $this->isCharged($s);
        $min = $late ? now()->addMinutes((int) $s->param('P-CHARGE-OFFSET')) : CarbonImmutable::now();
        $from = ($from ?? CarbonImmutable::now())->max($min);
        $to ??= CarbonImmutable::now()->addDays(Settings::int('P-BOOK-HORIZON'));
        $slots = $p->isBookable() ? $this->slots->availableSlots($p, $s->format, $from, $to, ['ignore_session_id' => $s->id]) : [];

        return [
            'data' => array_map(fn ($slot) => $slot->toIso8601String(), $slots),
            'late' => $late,
            'min_start' => $min->toIso8601String(),
            'late_reschedules_left' => $late ? max(0, (int) $s->param('P-LATE-RESCHEDULE-LIMIT') - (int) $s->late_reschedule_count) : null,
            'psychologist_timezone' => $p->timezone,
        ];
    }

    // ── Pair session (Q-54) ───────────────────────────────────────────────────────────────────────────────────

    public function acceptPartner(TherapySession $session, User $user): TherapySession
    {
        $s = DB::transaction(function () use ($session, $user) {
            $s = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (! $s->partner_email || mb_strtolower($s->partner_email) !== mb_strtolower($user->email)) {
                abort(403, 'Приглашение отправлено на другой email.');
            }
            if ($user->id === $s->client_id) {
                abort(422, 'Вы уже участник этой сессии.');
            }
            if (! $user->email_verified_at) {
                BookingError::fail('Подтвердите email, чтобы принять приглашение.', 'email_not_verified', 'email', 403);
            }
            if (! $user->isAdult()) {
                BookingError::fail('Участвовать в сессиях могут только совершеннолетние.', 'not_adult', 'birth_date');
            }
            if (! in_array($s->status, [TherapySession::BOOKED, TherapySession::PAID], true)) {
                BookingError::fail('Эта сессия уже недоступна.', 'invalid_status', 'session', 409);
            }
            if ($s->partner_user_id === $user->id) {
                return $s;
            }
            if ($s->partner_user_id) {
                abort(409, 'Приглашение уже принято.');
            }
            $s->forceFill(['partner_user_id' => $user->id, 'partner_accepted_at' => now()])->save();
            Outbox::record('book.pair_invite.accepted', $s, ['session_id' => $s->id, 'partner_user_id' => $user->id], $user->id);

            return $s;
        });
        $this->notify->pairAccepted($s);

        return $s;
    }

    // ── Client's psychologists (CL-04, DEC-48) ────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function myPsychologists(User $client): array
    {
        $rows = TherapySession::query()
            ->where('client_id', $client->id)
            ->selectRaw("psychologist_id,
                count(*) filter (where status in ('booked', 'paid') and starts_at > now()) as upcoming_sessions,
                max(starts_at) filter (where starts_at <= now() and status in ('in_progress', 'held', 'client_no_show', 'psy_no_show', 'tech_issue')) as last_session_at,
                max(starts_at) as latest")
            ->groupBy('psychologist_id')
            ->orderByDesc('latest')
            ->get();
        $psychologists = Psychologist::withTrashed()->whereIn('id', $rows->pluck('psychologist_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'psychologist_id' => $r->psychologist_id,
            'slug' => $psychologists[$r->psychologist_id]?->slug,
            'name' => $psychologists[$r->psychologist_id]?->fullName(),
            'upcoming_sessions' => (int) $r->upcoming_sessions,
            'last_session_at' => $r->last_session_at ? CarbonImmutable::parse($r->last_session_at, 'UTC')->toIso8601String() : null,
        ])->values()->all();
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $attributes */
    public function createSession(array $attributes, string $status, User $actor, string $mode): TherapySession
    {
        $session = new TherapySession;
        $session->forceFill([
            'reminders_sent' => ReminderService::passedThresholds(CarbonImmutable::parse($attributes['starts_at'])),
            ...$attributes,
            'status' => $status,
        ])->save();
        $session->recordInitialState($actor->id, [
            'kind' => 'booked',
            'payment_mode' => $mode,
            'price' => (int) $session->price,
            'discount' => (int) $session->discount,
            'amount_due' => (int) $session->amount_due,
            'amount_charged' => (int) $session->amount_charged,
            'source' => $session->source,
        ], event: 'book.session.booked');
        if ($status === TherapySession::PAID && $mode !== 'free_reschedule') {
            // Paid right at booking (late booking, balance, free, corporate): the "paid" fact is published as well,
            // e.g. for the referral reward (SEQ-19). A free reschedule only moves an existing payment.
            Outbox::record('book.session.paid', $session, [
                'from' => null,
                'to' => TherapySession::PAID,
                'kind' => 'paid_at_booking',
                'price' => (int) $session->price,
                'amount_charged' => (int) $session->amount_charged,
                'payment_source' => $session->payment_source,
            ], $actor->id);
        }

        return $session;
    }

    /** Letters, pair invitation and "Нет подходящего времени" requests answered by the booking. */
    public function afterBooked(TherapySession $session, string $paymentNote): void
    {
        $this->notify->booked($session, $paymentNote);
        if ($session->partner_email) {
            Outbox::record('book.pair_invite.created', $session, ['session_id' => $session->id, 'partner_email' => $session->partner_email], $session->client_id);
            $this->notify->pairInvitation($session);
        }
        SessionTimeRequest::where('client_id', $session->client_id)->where('psychologist_id', $session->psychologist_id)
            ->whereIn('status', ['open', 'offered'])
            ->update(['status' => 'booked', 'closed_at' => now(), 'updated_at' => now()]);
    }

    public function deferredNote(TherapySession $s): string
    {
        if ($s->amount_due <= 0) {
            return 'Сессия бесплатна по промокоду.';
        }

        return 'Оплата '.Money::format((int) $s->amount_due).' спишется автоматически '.SessionTime::format($s->charge_due_at, $s->client_timezone)
            .' — сначала с баланса личного кабинета, затем с привязанной карты. До этого момента отмена бесплатна.';
    }

    /** @return array<string, mixed> */
    public function paramSnapshot(): array
    {
        $out = [];
        foreach (self::PARAM_SNAPSHOT as $key) {
            $out[$key] = Settings::get($key);
        }

        return $out;
    }

    private function assertCanBook(User $client): void
    {
        if (! $client->email_verified_at) {
            BookingError::fail('Подтвердите email: ссылка отправлена на вашу почту.', 'email_not_verified', 'email', 403);
        }
        if (! $client->isAdult()) {
            BookingError::fail('Записаться на сессию могут только совершеннолетние (18+). Укажите дату рождения в настройках.', 'not_adult', 'birth_date');
        }
    }

    private function assertLimits(User $client, Psychologist $p, CarbonImmutable $start, string $format): void
    {
        $upcoming = TherapySession::where('client_id', $client->id)->where('psychologist_id', $p->id)
            ->whereIn('status', [TherapySession::BOOKED, TherapySession::PAID])
            ->where('starts_at', '>', now())->count();
        $max = Settings::int('P-BOOK-MAX-UPCOMING');
        if ($upcoming >= $max) {
            BookingError::fail("У вас уже {$upcoming} предстоящие сессии у этого психолога — это максимум. Новую запись можно сделать после ближайшей сессии.", 'max_upcoming', 'starts_at');
        }
        $end = $start->addMinutes($this->slots->duration($format));
        $overlap = TherapySession::where('client_id', $client->id)->occupying()
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
        if ($overlap) {
            BookingError::fail('У вас уже есть сессия в это время.', 'client_overlap', 'starts_at');
        }
    }

    private function findHold(User $client, Psychologist $p, CarbonImmutable $start, string $format, ?string $holdId, ?string $guestToken): ?SlotHold
    {
        $q = SlotHold::where('psychologist_id', $p->id)->where('starts_at', $start)->where('format', $format)->where('expires_at', '>', now());
        if ($holdId) {
            $hold = (clone $q)->whereKey($holdId)->first();
            if ($hold && $hold->user_id === null && $guestToken && hash_equals((string) $hold->guest_token, $guestToken)) {
                $hold->forceFill(['user_id' => $client->id, 'guest_token' => null])->save();

                return $hold;
            }
            if ($hold && $hold->user_id === $client->id) {
                return $hold;
            }
        }

        return $q->where('user_id', $client->id)->first();
    }

    private function ensureHold(?SlotHold $hold, User $client, Psychologist $p, CarbonImmutable $start, string $format): SlotHold
    {
        $expires = now()->addMinutes(Settings::int('P-SLOT-HOLD'));
        if ($hold) {
            $hold->forceFill(['expires_at' => $expires])->save();

            return $hold;
        }

        return SlotHold::create([
            'psychologist_id' => $p->id,
            'user_id' => $client->id,
            'format' => $format,
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($this->slots->duration($format)),
            'expires_at' => $expires,
        ]);
    }

    /** @param  array<string, mixed>  $quote */
    private function reservePromo(array $quote, User $client, TherapySession $session, bool $consume): void
    {
        if (! $quote['promo_code']) {
            return;
        }
        $this->promo->reserve($quote['promo_code'], $client, $session);
        if ($consume) {
            $this->promo->consume($session->fresh());
        }
    }

    /** A payment that cannot be used (slot lost, duplicate) is returned to the card in full. */
    private function returnPayment(Payment $payment, BookingIntent $intent): void
    {
        DB::afterCommit(function () use ($payment, $intent) {
            try {
                $this->payments->refundToCard($payment->fresh(), $payment->amount, $intent, 'booking_not_created');
            } catch (Throwable $e) {
                Log::error('Automatic refund failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);
            }
        });
        $client = User::find($payment->user_id);
        if ($client) {
            $this->notifier->send($client, 'pay.payment_returned', ['amount' => Money::format($payment->amount)], '/client/payments');
        }
    }

    private function rulesText(string $mode, CarbonImmutable $chargeAt): string
    {
        return match ($mode) {
            'deferred' => 'Отмена и перенос бесплатны до момента списания оплаты. После списания при отмене оплата не возвращается; перенос возможен на время не раньше чем через 12 часов.',
            'immediate' => 'До начала меньше 12 часов: оплата списывается сразу. При отмене оплата не возвращается; перенос возможен на время не раньше чем через 12 часов.',
            default => 'Сессию оплачивает компания. Отмена до момента списания (за 12 часов до начала) сохраняет лимит, позже — сессия засчитывается в лимит.',
        };
    }
}

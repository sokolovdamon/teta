<?php

namespace App\Modules\Payouts\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Notifications\Notifier;
use App\Modules\Payments\Gateway\GatewayOperation;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\PayoutRequest;
use App\Modules\Payments\Gateway\WebhookEvent;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payouts\Jobs\SendRegistryPayouts;
use App\Modules\Payouts\Models\Accrual;
use App\Modules\Payouts\Models\Payout;
use App\Modules\Payouts\Models\PayoutRegistry;
use App\Modules\Psychologists\Services\ActivityService;
use App\Support\Events\Outbox;
use App\Support\Money;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * SEQ-08, ST-07: weekly payout registry.
 *
 * On the payout day (P-PAYOUT-PERIOD, Moscow time) every psychologist or supervisor with a positive balance earned
 * before the period end gets a line: blocked by the monthly supervision requirement (DEC-37 p. 8), deferred
 * (suspended, no payout card, below P-PAYOUT-MIN) or put into the registry. The registry is approved automatically
 * (P-PAYOUT-AUTO-APPROVE) or by an admin in ADM-08, then each line is sent through the gateway with the payout id
 * as the idempotency key: a line is sent only from "in_registry", so it can never be paid twice. Webhooks and
 * payout:check-unknown move it to paid or rejected. Tax status is not checked (DEC-20).
 */
class PayoutRegistryService
{
    private const WEEKDAYS = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];

    public function __construct(
        private PayeeBalanceService $balances,
        private AccrualService $accruals,
        private ActivityService $activity,
        private Notifier $notifier,
    ) {}

    /** ISO weekday of the payout from P-PAYOUT-PERIOD ("weekly_monday"). */
    public static function payoutWeekday(): int
    {
        $value = (string) Settings::get('P-PAYOUT-PERIOD');

        return self::WEEKDAYS[str_replace('weekly_', '', $value)] ?? 1;
    }

    public static function isPayoutDay(?CarbonImmutable $at = null): bool
    {
        return self::moscow($at)->dayOfWeekIso === self::payoutWeekday();
    }

    /**
     * The completed week for a run at $at: [start, boundary) in Moscow time, boundary = 00:00 of the latest payout day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function periodFor(?CarbonImmutable $at = null): array
    {
        $at = self::moscow($at);
        $boundary = $at->startOfDay();
        while ($boundary->dayOfWeekIso !== self::payoutWeekday()) {
            $boundary = $boundary->subDay();
        }

        return [$boundary->subDays(7), $boundary];
    }

    /** Next scheduled run (03:00 MSK on the payout day) after $now. */
    public static function nextPayoutAt(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now = self::moscow($now);
        $candidate = $now->startOfDay()->setTime(3, 0);
        while ($candidate->dayOfWeekIso !== self::payoutWeekday() || $candidate <= $now) {
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }

    /** Build the registry for the completed week (idempotent per period). */
    public function build(?CarbonImmutable $at = null, ?string $actorId = null): PayoutRegistry
    {
        [$start, $boundary] = self::periodFor($at ?? CarbonImmutable::now());
        $periodStart = $start->toDateString();
        $periodEnd = $boundary->subDay()->toDateString();

        $existing = PayoutRegistry::whereDate('period_start', $periodStart)->whereDate('period_end', $periodEnd)->first();
        if ($existing) {
            return $existing;
        }

        try {
            $registry = DB::transaction(function () use ($periodStart, $periodEnd, $boundary, $actorId) {
                $registry = PayoutRegistry::create([
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'status' => 'draft',
                    'auto_approve' => (bool) Settings::get('P-PAYOUT-AUTO-APPROVE'),
                ]);

                $payees = Accrual::where('status', 'accrued')
                    ->where(fn ($q) => $q->whereNull('occurred_at')->orWhere('occurred_at', '<', $boundary))
                    ->groupBy('user_id')
                    ->havingRaw('sum(amount - reversed_amount) > 0')
                    ->orderBy('user_id')
                    ->pluck('user_id');

                foreach ($payees as $userId) {
                    $this->buildLine($registry, $userId, $boundary);
                }

                $summary = $this->summary($registry);
                if ($summary['lines'] === 0) {
                    $registry->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
                }
                Outbox::record('payout.registry.built', $registry, $summary, $actorId);

                return $registry;
            });
        } catch (UniqueConstraintViolationException) {
            return PayoutRegistry::whereDate('period_start', $periodStart)->whereDate('period_end', $periodEnd)->firstOrFail();
        }

        if ($actorId) {
            Audit::log('ADM-08', 'payout_registry.built', $registry, ['period_start' => $periodStart, 'period_end' => $periodEnd], userId: $actorId);
        }
        if ($registry->status === 'draft' && $registry->auto_approve) {
            $this->approve($registry);
        }

        return $registry->fresh();
    }

    /** ADM-08 (or automatic approval): the registry is approved and its lines are sent through the payouts queue. */
    public function approve(PayoutRegistry $registry, ?User $actor = null): PayoutRegistry
    {
        DB::transaction(function () use ($registry, $actor) {
            $locked = PayoutRegistry::whereKey($registry->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'draft', 422, 'Реестр уже утверждён.');
            $locked->forceFill(['status' => 'approved', 'approved_by' => $actor?->id, 'approved_at' => now()])->save();
            Outbox::record('payout.registry.approved', $locked, ['auto' => $actor === null], $actor?->id);
            if ($actor) {
                Audit::log('ADM-08', 'payout_registry.approved', $locked, $this->summary($locked), userId: $actor->id);
            }
            SendRegistryPayouts::dispatch($locked->id)->afterCommit();
        });

        return $registry->fresh();
    }

    /** Send every line still in the registry (job on the "payouts" queue). */
    public function sendRegistry(PayoutRegistry $registry): void
    {
        if (! in_array($registry->status, ['approved', 'sent'], true)) {
            return;
        }
        foreach (Payout::where('payout_registry_id', $registry->id)->where('status', 'in_registry')->pluck('id') as $id) {
            $this->sendPayout($id);
        }
        $registry->refresh();
        if ($registry->status === 'approved' && ! Payout::where('payout_registry_id', $registry->id)->where('status', 'in_registry')->exists()) {
            $registry->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        }
        $this->completeIfDone($registry->id);
    }

    /** Send one line. Only a line in "in_registry" is sent, and it is moved to "sent" before the gateway call. */
    public function sendPayout(string $payoutId): void
    {
        $prepared = DB::transaction(function () use ($payoutId) {
            $payout = Payout::whereKey($payoutId)->lockForUpdate()->first();
            if (! $payout || $payout->status !== 'in_registry') {
                return null;
            }
            $registry = PayoutRegistry::find($payout->payout_registry_id);
            if (! $registry || ! in_array($registry->status, ['approved', 'sent'], true)) {
                return null;
            }
            $card = PayoutCardService::activeCard($payout->user_id);
            if (! $card) {
                $this->releaseLine($payout, 'excluded', 'Карта для выплат удалена до отправки');
                $this->notify($payout->user_id, 'payout.no_card', ['amount' => Money::format((int) $payout->amount)]);

                return null;
            }
            $payout->transitionTo('sent', null, null, ['sent_at' => now(), 'payment_method_id' => $card->id], ['amount' => (int) $payout->amount]);

            return [$payout, $card, $registry];
        });
        if (! $prepared) {
            return;
        }
        /** @var array{0: Payout, 1: PaymentMethod, 2: PayoutRegistry} $prepared */
        [$payout, $card, $registry] = $prepared;

        $this->callGateway($payout, $card, $registry);
    }

    /** Webhook "payout.paid" / "payout.rejected" from the gateway (registered in PayoutsServiceProvider). */
    public function handleWebhook(WebhookEvent $event): void
    {
        $payout = Payout::where('idempotency_key', $event->idempotencyKey)->first()
            ?? ($event->operation->gatewayId ? Payout::where('gateway_payout_id', $event->operation->gatewayId)->first() : null);
        if (! $payout) {
            Log::warning("Payout webhook for unknown payout {$event->idempotencyKey}");

            return;
        }

        match ($event->type) {
            'payout.paid' => $this->markPaid($payout->id, $event->operation->gatewayId),
            'payout.rejected' => $this->markRejected($payout->id, $event->operation->errorCode),
            default => null,
        };
    }

    /** payout:check-unknown — no webhook within P-PAYOUT-WEBHOOK-WAIT: ask the gateway with the same key. */
    public function checkUnknown(): int
    {
        $threshold = now()->subMinutes(Settings::int('P-PAYOUT-WEBHOOK-WAIT'));
        $ids = Payout::where(fn ($q) => $q->where('status', 'unknown')
            ->orWhere(fn ($s) => $s->where('status', 'sent')->where('sent_at', '<=', $threshold)))
            ->pluck('id');
        foreach ($ids as $id) {
            $this->checkStatus($id);
        }

        return $ids->count();
    }

    public function checkStatus(string $payoutId): void
    {
        $payout = Payout::find($payoutId);
        if (! $payout || ! in_array($payout->status, ['sent', 'unknown'], true)) {
            return;
        }
        try {
            $operation = $this->gateway()->payoutStatus($payout->idempotency_key);
        } catch (Throwable $e) {
            Log::warning("Payout status check failed for {$payout->id}: ".$e->getMessage());
            $this->markUnknown($payout->id);

            return;
        }
        $payout->forceFill(['status_checked_at' => now()])->save();
        $this->applyOperation($payout->id, $operation);
        if (Payout::whereKey($payout->id)->value('status') === 'sent') {
            $this->markUnknown($payout->id);
        }
    }

    /**
     * ADM-08 "retry": send lines still in the registry; re-check lines with unknown status and, if the gateway does
     * not know the operation, repeat the call with the same idempotency key (never a second payout).
     *
     * @return array{sent: int, checked: int}
     */
    public function retry(PayoutRegistry $registry, User $actor): array
    {
        abort_if($registry->status === 'draft', 422, 'Сначала утвердите реестр.');
        $stats = ['sent' => 0, 'checked' => 0];

        foreach (Payout::where('payout_registry_id', $registry->id)->where('status', 'in_registry')->pluck('id') as $id) {
            $this->sendPayout($id);
            $stats['sent']++;
        }
        foreach (Payout::where('payout_registry_id', $registry->id)->whereIn('status', ['sent', 'unknown'])->get() as $payout) {
            $this->checkStatus($payout->id);
            $stats['checked']++;
            $payout->refresh();
            if ($payout->status === 'unknown') {
                $card = $payout->payment_method_id ? PaymentMethod::find($payout->payment_method_id) : PayoutCardService::activeCard($payout->user_id);
                if ($card) {
                    $this->callGateway($payout, $card, $registry);
                }
            }
        }
        if ($registry->status === 'approved' && ! Payout::where('payout_registry_id', $registry->id)->where('status', 'in_registry')->exists()) {
            $registry->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        }
        $this->completeIfDone($registry->id);
        Audit::log('ADM-08', 'payout_registry.retried', $registry, $stats, userId: $actor->id);

        return $stats;
    }

    /** ADM-08: exclude a line during manual approval; the reason is required and the money stays on the balance. */
    public function exclude(Payout $payout, string $reason, User $actor): Payout
    {
        DB::transaction(function () use ($payout, $reason, $actor) {
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'in_registry', 422, 'Исключить можно только строку, которая ещё не отправлена.');
            $this->releaseLine($locked, 'excluded', $reason, $actor->id);
            Audit::log('ADM-08', 'payout.excluded', $locked, ['amount' => (int) $locked->amount, 'user_id' => $locked->user_id], $reason, $actor->id);
        });
        $this->notify($payout->user_id, 'payout.excluded', ['amount' => Money::format((int) $payout->amount), 'reason' => $reason]);
        $this->completeIfDone($payout->payout_registry_id);

        return $payout->fresh();
    }

    public function markPaid(string $payoutId, ?string $gatewayId = null): bool
    {
        $done = DB::transaction(function () use ($payoutId, $gatewayId) {
            $payout = Payout::whereKey($payoutId)->lockForUpdate()->first();
            if (! $payout || ! in_array($payout->status, ['sent', 'unknown'], true)) {
                return null;
            }
            $this->balances->lock($payout->user_id);
            $payout->transitionTo('paid', null, null, array_filter(['paid_at' => now(), 'gateway_payout_id' => $gatewayId ?? $payout->gateway_payout_id]), ['amount' => (int) $payout->amount]);

            foreach (Accrual::where('payout_id', $payout->id)->where('status', 'in_registry')->lockForUpdate()->get() as $accrual) {
                $accrual->transitionTo('paid');
                if (Accrual::where('correction_of_id', $accrual->id)->exists()) {
                    $accrual->transitionTo('corrected', null, $accrual->reason ?: 'Корректировка после выплаты');
                }
            }
            $this->balances->refresh($payout->user_id);

            return $payout;
        });
        if (! $done) {
            return false;
        }
        $this->notify($done->user_id, 'payout.paid', ['amount' => Money::format((int) $done->amount), 'card' => $this->cardMask($done)]);
        $this->completeIfDone($done->payout_registry_id);

        return true;
    }

    public function markRejected(string $payoutId, ?string $errorCode = null): bool
    {
        $done = DB::transaction(function () use ($payoutId, $errorCode) {
            $payout = Payout::whereKey($payoutId)->lockForUpdate()->first();
            if (! $payout || ! in_array($payout->status, ['sent', 'unknown'], true)) {
                return null;
            }
            $payout->forceFill(['error_code' => $errorCode])->save();
            $this->releaseLine($payout, 'rejected', 'Платёжный сервис отклонил выплату'.($errorCode ? " ({$errorCode})" : '').': сумма вернулась на баланс и попадёт в следующую выплату');

            return $payout;
        });
        if (! $done) {
            return false;
        }
        $this->notify($done->user_id, 'payout.rejected', ['amount' => Money::format((int) $done->amount), 'card' => $this->cardMask($done)]);
        $this->completeIfDone($done->payout_registry_id);

        return true;
    }

    /** @return array{lines: int, amount: int, blocked: int, deferred: int, by_status: array<string, int>} */
    public function summary(PayoutRegistry $registry): array
    {
        $rows = Payout::where('payout_registry_id', $registry->id)
            ->selectRaw('status, count(*) as cnt, coalesce(sum(amount), 0) as total')
            ->groupBy('status')
            ->get();
        $byStatus = $rows->mapWithKeys(fn ($r) => [$r->status => (int) $r->cnt])->all();
        $lines = $rows->whereIn('status', ['in_registry', 'sent', 'unknown', 'paid', 'rejected'])->sum('cnt');
        $amount = $rows->whereIn('status', ['in_registry', 'sent', 'unknown', 'paid'])->sum('total');

        return [
            'lines' => (int) $lines,
            'amount' => (int) $amount,
            'paid_amount' => (int) ($rows->firstWhere('status', 'paid')?->total ?? 0),
            'blocked' => (int) ($byStatus['blocked_supervision'] ?? 0),
            'deferred' => (int) ($byStatus['deferred'] ?? 0),
            'by_status' => $byStatus,
        ];
    }

    private function buildLine(PayoutRegistry $registry, string $userId, CarbonImmutable $boundary): void
    {
        $balance = $this->balances->lock($userId);
        $accruals = Accrual::where('user_id', $userId)
            ->where('status', 'accrued')
            ->where(fn ($q) => $q->whereNull('occurred_at')->orWhere('occurred_at', '<', $boundary))
            ->lockForUpdate()
            ->get();
        $amount = (int) $accruals->sum(fn (Accrual $a) => $a->net());
        if ($amount <= 0) {
            return;
        }

        $id = (string) Str::uuid();
        $payout = new Payout(['payout_registry_id' => $registry->id, 'user_id' => $userId, 'amount' => $amount]);
        $payout->forceFill(['id' => $id, 'idempotency_key' => $id, 'status' => 'checking'])->save();
        $payout->recordInitialState(null, ['amount' => $amount, 'registry_id' => $registry->id]);

        $user = User::with('roles', 'psychologist')->find($userId);
        $vars = ['amount' => Money::format($amount)];

        if (! $user || ! $this->activity->payoutAllowed($user)) {
            $reason = 'Не выполнено требование ежемесячной супервизии: начисления копятся на балансе до засчитанной супервизии';
            $payout->transitionTo('blocked_supervision', null, $reason, ['reason' => $reason], ['amount' => $amount], event: 'payout.blocked_by_supervision');
            $this->notify($userId, 'payout.blocked_supervision', $vars);

            return;
        }
        if ($balance->payouts_suspended) {
            $this->defer($payout, 'Выплаты приостановлены администратором'.($balance->suspended_reason ? ": {$balance->suspended_reason}" : ''));

            return;
        }
        $card = PayoutCardService::activeCard($userId);
        if (! $card) {
            $this->defer($payout, 'Не привязана карта для выплат');
            $this->notify($userId, 'payout.no_card', $vars);

            return;
        }
        $min = Settings::int('P-PAYOUT-MIN');
        if ($amount < $min) {
            $this->defer($payout, 'Сумма меньше минимальной выплаты '.Money::format($min).': она перейдёт в следующий период');

            return;
        }

        $payout->transitionTo('in_registry', null, null, ['payment_method_id' => $card->id], ['amount' => $amount]);
        foreach ($accruals as $accrual) {
            $accrual->transitionTo('in_registry', null, null, ['payout_id' => $payout->id], ['payout_id' => $payout->id]);
        }
        $this->balances->refresh($userId);
    }

    private function defer(Payout $payout, string $reason): void
    {
        $payout->transitionTo('deferred', null, $reason, ['reason' => $reason], ['amount' => (int) $payout->amount]);
    }

    /** Line leaves the registry (excluded or rejected): its accruals return to "accrued" for the next registry. */
    private function releaseLine(Payout $payout, string $to, string $reason, ?string $actorId = null): void
    {
        $this->balances->lock($payout->user_id);
        $payout->transitionTo($to, $actorId, $reason, ['reason' => $reason], ['amount' => (int) $payout->amount]);
        foreach (Accrual::where('payout_id', $payout->id)->where('status', 'in_registry')->lockForUpdate()->get() as $accrual) {
            $accrual->transitionTo('accrued', $actorId, $reason, ['payout_id' => null], ['payout_id' => $payout->id]);
            $this->accruals->foldPendingCorrections($accrual);
        }
        $this->balances->refresh($payout->user_id);
    }

    private function callGateway(Payout $payout, PaymentMethod $card, PayoutRegistry $registry): void
    {
        $period = $registry->period_start->format('d.m.Y').'–'.$registry->period_end->format('d.m.Y');
        try {
            $operation = $this->gateway()->payout(new PayoutRequest(
                $payout->idempotency_key,
                $card->token,
                (int) $payout->amount,
                "Выплата ТЕТА за период {$period}",
            ));
        } catch (Throwable $e) {
            // The line stays "sent"; payout:check-unknown asks the gateway with the same key.
            Log::warning("Payout {$payout->id} send failed: ".$e->getMessage());

            return;
        }
        if ($operation->gatewayId) {
            Payout::whereKey($payout->id)->whereNull('gateway_payout_id')->update(['gateway_payout_id' => $operation->gatewayId]);
        }
        $this->applyOperation($payout->id, $operation);
        if (Payout::whereKey($payout->id)->value('status') === 'sent') {
            $this->notify($payout->user_id, 'payout.sent', ['amount' => Money::format((int) $payout->amount), 'card' => (string) $card->card_mask]);
        }
    }

    private function applyOperation(string $payoutId, GatewayOperation $operation): void
    {
        match ($operation->status) {
            GatewayOperation::SUCCEEDED => $this->markPaid($payoutId, $operation->gatewayId),
            GatewayOperation::DECLINED => $this->markRejected($payoutId, $operation->errorCode),
            GatewayOperation::UNKNOWN => $this->markUnknown($payoutId),
            default => null,
        };
    }

    private function markUnknown(string $payoutId): void
    {
        DB::transaction(function () use ($payoutId) {
            $payout = Payout::whereKey($payoutId)->lockForUpdate()->first();
            if ($payout && $payout->status === 'sent') {
                $payout->transitionTo('unknown', null, 'Нет ответа платёжного сервиса, статус уточняется', ['status_checked_at' => now()]);
            }
        });
    }

    private function completeIfDone(?string $registryId): void
    {
        if (! $registryId) {
            return;
        }
        DB::transaction(function () use ($registryId) {
            $registry = PayoutRegistry::whereKey($registryId)->lockForUpdate()->first();
            if (! $registry || ! in_array($registry->status, ['approved', 'sent'], true)) {
                return;
            }
            $open = Payout::where('payout_registry_id', $registryId)->whereIn('status', ['in_registry', 'sent', 'unknown'])->exists();
            if (! $open) {
                $registry->forceFill(['status' => 'completed', 'completed_at' => now(), 'sent_at' => $registry->sent_at ?? now()])->save();
                Outbox::record('payout.registry.completed', $registry, $this->summary($registry));
            }
        });
    }

    private function cardMask(Payout $payout): string
    {
        return (string) ($payout->payment_method_id ? PaymentMethod::whereKey($payout->payment_method_id)->value('card_mask') : '');
    }

    /** @param  array<string, mixed>  $vars */
    private function notify(string $userId, string $code, array $vars): void
    {
        $user = User::find($userId);
        if ($user) {
            $this->notifier->send($user, $code, $vars, $code === 'payout.blocked_supervision' ? '/pro/supervision' : '/pro/payouts');
        }
    }

    private function gateway(): PaymentGateway
    {
        return app(PaymentGateway::class);
    }

    private static function moscow(?CarbonImmutable $at): CarbonImmutable
    {
        return ($at ?? CarbonImmutable::now())->setTimezone(config('platform.timezone'));
    }
}

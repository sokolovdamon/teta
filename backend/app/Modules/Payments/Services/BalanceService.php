<?php

namespace App\Modules\Payments\Services;

use App\Modules\Audit\Audit;
use App\Modules\Payments\Models\ClientBalance;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ST-04, Q-52 [assumption]: the client cabinet balance. Every refund is credited here; the next payment spends
 * the balance first and the card second; the remainder (except certificate funds) can be withdrawn to the card.
 *
 * The balance is the sum of operations. client_balances is materialised in the same transaction under a row lock
 * and reconciled daily. Certificate funds (DEC-43, DM-14) are spent first and never withdrawn.
 *
 *   available             = credited − spends (reserved or spent) − withdrawals (reserved … withdrawn)
 *   certificate_available = certificate credits − certificate part of spends
 *   reserved              = spends and withdrawals still in progress
 *   withdrawable          = available − certificate_available
 */
class BalanceService
{
    public const SPEND_ACTIVE = ['spend_reserved', 'spent'];

    public const WITHDRAW_ACTIVE = ['withdraw_reserved', 'withdraw_processing', 'withdraw_review', 'withdrawn'];

    public const IN_PROGRESS = ['spend_reserved', 'withdraw_reserved', 'withdraw_processing', 'withdraw_review'];

    /** Balance row of the user, created if missing and locked until the end of the transaction. */
    public function lock(string $userId): ClientBalance
    {
        ClientBalance::query()->insertOrIgnore(['user_id' => $userId, 'available' => 0, 'reserved' => 0, 'certificate_available' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return ClientBalance::whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    /** @return array{available: int, certificate_available: int, withdrawable: int, reserved: int} */
    public function compute(string $userId): array
    {
        $row = DB::table('client_balance_operations')->where('user_id', $userId)->selectRaw("
            coalesce(sum(case when status = 'credited' then amount else 0 end), 0) as credited,
            coalesce(sum(case when status = 'credited' and is_certificate_funds then amount else 0 end), 0) as cert_credited,
            coalesce(sum(case when status in ('spend_reserved', 'spent') then amount else 0 end), 0) as spent,
            coalesce(sum(case when status in ('spend_reserved', 'spent') then certificate_amount else 0 end), 0) as cert_spent,
            coalesce(sum(case when status in ('withdraw_reserved', 'withdraw_processing', 'withdraw_review', 'withdrawn') then amount else 0 end), 0) as withdrawn,
            coalesce(sum(case when status in ('spend_reserved', 'withdraw_reserved', 'withdraw_processing', 'withdraw_review') then amount else 0 end), 0) as reserved
        ")->first();

        $available = (int) $row->credited - (int) $row->spent - (int) $row->withdrawn;
        $certificate = (int) $row->cert_credited - (int) $row->cert_spent;

        return [
            'available' => $available,
            'certificate_available' => $certificate,
            'withdrawable' => max(0, $available - $certificate),
            'reserved' => (int) $row->reserved,
        ];
    }

    /** @return array{available: int, certificate_available: int, withdrawable: int, reserved: int} */
    public function summary(string $userId): array
    {
        $balance = ClientBalance::find($userId);
        if (! $balance) {
            return ['available' => 0, 'certificate_available' => 0, 'withdrawable' => 0, 'reserved' => 0];
        }

        return [
            'available' => (int) $balance->available,
            'certificate_available' => (int) $balance->certificate_available,
            'withdrawable' => max(0, (int) $balance->available - (int) $balance->certificate_available),
            'reserved' => (int) $balance->reserved,
        ];
    }

    /** Recompute the materialised balance inside the current transaction (the row must be locked). */
    public function refresh(string $userId): ClientBalance
    {
        $balance = $this->lock($userId);
        $c = $this->compute($userId);
        if ($c['available'] < 0 || $c['certificate_available'] < 0) {
            throw new \LogicException('Client balance cannot become negative.');
        }
        $balance->forceFill(['available' => $c['available'], 'reserved' => $c['reserved'], 'certificate_available' => $c['certificate_available']])->save();

        return $balance;
    }

    /** Credit (refund to the balance, change of psychologist, approved complaint, certificate activation). */
    public function credit(string $userId, int $amount, string $reason, ?Model $source = null, ?Payment $payment = null, bool $certificate = false, ?string $comment = null, ?string $actorId = null): ?ClientBalanceOperation
    {
        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($userId, $amount, $reason, $source, $payment, $certificate, $comment, $actorId) {
            $this->lock($userId);
            $op = $this->newOperation($userId, 'credit', 'credited', $amount, $reason, $source, $payment, $comment, $actorId, ['is_certificate_funds' => $certificate]);
            $op->recordInitialState($actorId, ['amount' => $amount, 'reason' => $reason, 'certificate' => $certificate, 'user_id' => $userId], event: 'pay.balance.credited');
            $this->refresh($userId);

            return $op;
        });
    }

    /**
     * Reserve up to $max from the balance for a payment (certificate funds first). Returns null when the balance is empty.
     */
    public function reserveSpend(string $userId, int $max, ?Model $source, string $reason = 'session_payment'): ?ClientBalanceOperation
    {
        return DB::transaction(function () use ($userId, $max, $source, $reason) {
            $this->lock($userId);
            $c = $this->compute($userId);
            $amount = min($max, $c['available']);
            if ($amount <= 0) {
                return null;
            }
            $op = $this->newOperation($userId, 'spend', 'spend_reserved', $amount, $reason, $source, null, null, null, [
                'certificate_amount' => min($amount, max(0, $c['certificate_available'])),
            ]);
            $op->recordInitialState(null, ['amount' => $amount, 'reason' => $reason, 'user_id' => $userId], event: 'pay.balance.spend_reserved');
            $this->refresh($userId);

            return $op;
        });
    }

    public function confirmSpend(ClientBalanceOperation $op, ?Model $source = null): void
    {
        DB::transaction(function () use ($op, $source) {
            $this->lock($op->user_id);
            $op = ClientBalanceOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            if ($op->status !== 'spend_reserved') {
                return;
            }
            $attrs = $source ? ['source_type' => $source->getMorphClass(), 'source_id' => $source->getKey()] : [];
            $op->transitionTo('spent', attributes: $attrs, context: ['amount' => $op->amount, 'user_id' => $op->user_id]);
            $this->refresh($op->user_id);
        });
    }

    /** Return a spend reservation (payment failed at the deadline or the session was cancelled before payment). */
    public function reverseSpend(ClientBalanceOperation $op, ?string $reason = null): void
    {
        DB::transaction(function () use ($op, $reason) {
            $this->lock($op->user_id);
            $op = ClientBalanceOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            if ($op->status !== 'spend_reserved') {
                return;
            }
            $op->transitionTo('spend_reversed', reason: $reason, context: ['amount' => $op->amount, 'user_id' => $op->user_id]);
            $this->refresh($op->user_id);
        });
    }

    /** Daily reconciliation of the materialised balances with the operations [Рек.]. Returns fixed user ids. */
    public function reconcile(): array
    {
        $fixed = [];
        $userIds = DB::table('client_balance_operations')->distinct()->pluck('user_id')
            ->merge(DB::table('client_balances')->pluck('user_id'))->unique();
        foreach ($userIds as $userId) {
            DB::transaction(function () use ($userId, &$fixed) {
                $balance = $this->lock($userId);
                $c = $this->compute($userId);
                if ((int) $balance->available !== $c['available'] || (int) $balance->reserved !== $c['reserved'] || (int) $balance->certificate_available !== $c['certificate_available']) {
                    Log::warning('Client balance mismatch fixed', ['user_id' => $userId, 'stored' => $balance->only(['available', 'reserved', 'certificate_available']), 'computed' => $c]);
                    Audit::log('ADM-07', 'balance.reconciled', null, ['user_id' => $userId, 'stored' => $balance->only(['available', 'reserved', 'certificate_available']), 'computed' => $c], 'Сверка баланса клиента');
                    $balance->forceFill(['available' => $c['available'], 'reserved' => $c['reserved'], 'certificate_available' => $c['certificate_available']])->save();
                    $fixed[] = $userId;
                }
            });
        }

        return $fixed;
    }

    /** @param  array<string, mixed>  $extra */
    public function newOperation(string $userId, string $type, string $status, int $amount, string $reason, ?Model $source, ?Payment $payment, ?string $comment, ?string $actorId, array $extra = []): ClientBalanceOperation
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма операции должна быть больше нуля.']);
        }
        $op = new ClientBalanceOperation;
        $op->forceFill([
            'user_id' => $userId,
            'type' => $type,
            'status' => $status,
            'amount' => $amount,
            'reason' => $reason,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'payment_id' => $payment?->id,
            'comment' => $comment,
            'created_by' => $actorId,
            ...$extra,
        ])->save();

        return $op;
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'psy_cancel' => 'Возврат: психолог отменил сессию',
            'psy_no_show' => 'Возврат: психолог не пришёл на сессию',
            'tech_issue' => 'Возврат: техническая проблема на сессии',
            'change_psychologist' => 'Возврат: смена психолога',
            'complaint' => 'Возврат по жалобе на списание',
            'block' => 'Возврат: сессия отменена платформой',
            'admin' => 'Начисление администратором',
            'late_cancel' => 'Частичный возврат при поздней отмене',
            'certificate' => 'Подарочный сертификат',
            'session_payment' => 'Оплата сессии',
            'booking_payment' => 'Оплата записи',
            'withdrawal' => 'Вывод на карту',
            'booking_failed' => 'Возврат: запись не состоялась',
            default => 'Операция по балансу',
        };
    }

    public static function toApi(ClientBalanceOperation $op): array
    {
        return [
            'id' => $op->id,
            'type' => $op->type,
            'status' => $op->status,
            'amount' => $op->amount,
            'certificate_amount' => $op->type === 'credit' ? ($op->is_certificate_funds ? $op->amount : 0) : (int) $op->certificate_amount,
            'is_certificate_funds' => $op->is_certificate_funds,
            'reason' => $op->reason,
            'reason_label' => self::reasonLabel($op->reason),
            'comment' => $op->comment,
            'source_type' => $op->source_type,
            'source_id' => $op->source_id,
            'created_at' => $op->created_at?->toIso8601String(),
        ];
    }
}

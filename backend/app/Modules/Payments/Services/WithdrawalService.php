<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Booking\Support\BookingError;
use App\Modules\Notifications\Notifier;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Support\AdminRecipients;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ST-04 withdrawal of the balance remainder to the card [assumption Q-52, ЮРИСТ]: the non-certificate remainder
 * is reserved, then returned as refunds over the client's original card payments within their amounts.
 * withdraw_reserved → withdraw_processing → withdrawn | withdraw_review (admin: withdrawn manually or cancelled).
 */
class WithdrawalService
{
    public function __construct(
        private BalanceService $balance,
        private PaymentService $payments,
        private Notifier $notifier,
    ) {}

    public function request(User $client): ClientBalanceOperation
    {
        $op = DB::transaction(function () use ($client) {
            $this->balance->lock($client->id);
            $c = $this->balance->compute($client->id);
            if ($c['withdrawable'] <= 0) {
                BookingError::fail('Выводить нечего: средства подарочного сертификата на карту не выводятся.', 'nothing_to_withdraw', 'amount');
            }
            if (ClientBalanceOperation::where('user_id', $client->id)->whereIn('status', ['withdraw_reserved', 'withdraw_processing', 'withdraw_review'])->exists()) {
                BookingError::fail('Предыдущая заявка на вывод ещё обрабатывается.', 'withdrawal_in_progress', 'amount', 409);
            }
            $op = $this->balance->newOperation($client->id, 'withdraw', 'withdraw_reserved', $c['withdrawable'], 'withdrawal', null, null, null, $client->id);
            $op->recordInitialState($client->id, ['amount' => $op->amount, 'user_id' => $client->id], event: 'pay.balance.withdrawal_requested');
            $this->balance->refresh($client->id);

            return $op;
        });
        $this->notifier->send($client, 'pay.withdrawal_requested', ['amount' => Money::format($op->amount)], '/client/payments');
        DB::afterCommit(fn () => $this->process($op->id));

        return $op;
    }

    /** Send refunds over the original payments (queue "payments"; also picked up by pay:process-withdrawals). */
    public function process(string $operationId): void
    {
        $plan = DB::transaction(function () use ($operationId) {
            $op = ClientBalanceOperation::whereKey($operationId)->lockForUpdate()->first();
            if (! $op || $op->status !== 'withdraw_reserved') {
                return null;
            }
            $left = $op->amount;
            $plan = [];
            $sources = Payment::where('user_id', $op->user_id)
                ->whereIn('purpose', ['session', 'booking'])
                ->whereIn('status', ['succeeded', 'partially_refunded'])
                ->orderByDesc('paid_at')->get();
            foreach ($sources as $payment) {
                $pending = (int) PaymentRefund::where('payment_id', $payment->id)->where('status', 'pending')->sum('amount');
                $available = $payment->refundable() - $pending;
                if ($available <= 0) {
                    continue;
                }
                $part = min($available, $left);
                $plan[] = [$payment->id, $part];
                $left -= $part;
                if ($left === 0) {
                    break;
                }
            }
            if ($left > 0) {
                // Original payments cannot cover the amount: an admin returns it manually.
                $op->transitionTo('withdraw_processing', reason: 'checking original payments');
                $op->transitionTo('withdraw_review', reason: 'original payments do not cover the amount', attributes: ['meta' => ['planned' => [], 'uncovered' => $op->amount, 'refunded' => 0]]);

                return ['review' => $op->id];
            }
            $op->transitionTo('withdraw_processing', reason: 'refunds sent', attributes: ['meta' => ['planned' => $plan, 'refunded' => 0, 'dispatched' => false]]);

            return ['refunds' => $plan, 'op' => $op->id];
        });
        if (! $plan) {
            return;
        }
        if (isset($plan['review'])) {
            $this->notifyReview(ClientBalanceOperation::findOrFail($plan['review']));

            return;
        }
        $op = ClientBalanceOperation::findOrFail($plan['op']);
        foreach ($plan['refunds'] as [$paymentId, $amount]) {
            try {
                $this->payments->refundToCard(Payment::findOrFail($paymentId), $amount, $op, 'withdrawal');
            } catch (Throwable $e) {
                report($e);
            }
        }
        DB::transaction(function () use ($op) {
            $fresh = ClientBalanceOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            $fresh->forceFill(['meta' => [...($fresh->meta ?? []), 'dispatched' => true]])->save();
        });
        $this->settle($op->id);
    }

    /** Called when a refund of this withdrawal got its final status. */
    public function refundSettled(PaymentRefund $refund): void
    {
        DB::afterCommit(fn () => $this->settle((string) $refund->source_id));
    }

    public function settle(string $operationId): void
    {
        $result = DB::transaction(function () use ($operationId) {
            $op = ClientBalanceOperation::whereKey($operationId)->lockForUpdate()->first();
            if (! $op || $op->status !== 'withdraw_processing') {
                return null;
            }
            if (! (($op->meta ?? [])['dispatched'] ?? false)) {
                return null;
            }
            $refunds = PaymentRefund::where('source_type', $op->getMorphClass())->where('source_id', $op->id)->get();
            if ($refunds->contains(fn ($r) => $r->status === 'pending')) {
                // Unknown result of a refund: pay:recover-stuck-charges queries its status later.
                return null;
            }
            $succeeded = (int) $refunds->where('status', 'succeeded')->sum('amount');
            $meta = [...($op->meta ?? []), 'refunded' => $succeeded];
            if ($succeeded >= $op->amount) {
                $op->transitionTo('withdrawn', reason: 'all refunds succeeded', attributes: ['meta' => $meta]);
                $this->balance->refresh($op->user_id);

                return ['withdrawn', $op];
            }
            $op->transitionTo('withdraw_review', reason: 'refund failed', attributes: ['meta' => $meta]);
            $this->balance->refresh($op->user_id);

            return ['review', $op];
        });
        if (! $result) {
            return;
        }
        [$state, $op] = $result;
        if ($state === 'withdrawn') {
            if ($client = User::find($op->user_id)) {
                $this->notifier->send($client, 'pay.withdrawal_completed', ['amount' => Money::format($op->amount)], '/client/payments');
            }
        } else {
            $this->notifyReview($op);
        }
    }

    /** ADM-07: resolve a withdrawal in review — "withdrawn" (refunded manually) or "cancel" (reservation released). */
    public function resolve(ClientBalanceOperation $op, User $admin, string $action, string $reason): ClientBalanceOperation
    {
        $op = DB::transaction(function () use ($op, $admin, $action, $reason) {
            $this->balance->lock($op->user_id);
            $op = ClientBalanceOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            if ($op->status !== 'withdraw_review') {
                BookingError::fail('Заявка не ожидает разбора.', 'invalid_status', 'action', 409);
            }
            if ($action === 'cancel' && (int) (($op->meta ?? [])['refunded'] ?? 0) > 0) {
                BookingError::fail('Часть суммы уже возвращена на карту — завершите вывод вручную.', 'partially_refunded', 'action', 409);
            }
            $to = $action === 'withdrawn' ? 'withdrawn' : 'withdraw_cancelled';
            $op->transitionTo($to, $admin->id, $reason, ['comment' => $reason]);
            $this->balance->refresh($op->user_id);
            Audit::log('ADM-07', 'withdrawal.'.$to, $op, ['amount' => $op->amount], $reason, $admin->id);

            return $op;
        });
        if ($client = User::find($op->user_id)) {
            $this->notifier->send($client, $op->status === 'withdrawn' ? 'pay.withdrawal_completed' : 'pay.withdrawal_cancelled', [
                'amount' => Money::format($op->amount),
                'reason' => $reason,
            ], '/client/payments');
        }

        return $op;
    }

    private function notifyReview(ClientBalanceOperation $op): void
    {
        foreach (AdminRecipients::withPermission('admin.finance.refund') as $admin) {
            $this->notifier->send($admin, 'pay.withdrawal_review_admin', ['amount' => Money::format($op->amount)], '/admin/finance?tab=balance');
        }
    }
}

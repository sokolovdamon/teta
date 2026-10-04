<?php

namespace App\Modules\Payouts\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Notifications\Notifier;
use App\Modules\Payouts\Models\Accrual;
use App\Modules\Payouts\Models\PayeeBalance;
use App\Modules\Payouts\Models\Payout;
use Illuminate\Support\Facades\DB;

/**
 * Materialized payee balance (DEC-20: shown net of commission). Every ledger change locks the payee's balance row,
 * changes accruals and recomputes the balance from the ledger in the same transaction, so the balance never drifts.
 */
class PayeeBalanceService
{
    public function __construct(private Notifier $notifier) {}

    /** Lock the payee's balance row (created on first use); serializes ledger changes per payee. */
    public function lock(string $userId): PayeeBalance
    {
        PayeeBalance::query()->insertOrIgnore(['user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);

        return PayeeBalance::whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    public function refresh(string $userId): PayeeBalance
    {
        return DB::transaction(function () use ($userId) {
            $balance = $this->lock($userId);
            $sums = Accrual::where('user_id', $userId)
                ->whereIn('status', ['accrued', 'in_registry'])
                ->selectRaw('status, coalesce(sum(amount - reversed_amount), 0) as total')
                ->groupBy('status')
                ->pluck('total', 'status');
            $paid = (int) Payout::where('user_id', $userId)->where('status', 'paid')->sum('amount');
            $balance->forceFill([
                'available' => (int) ($sums['accrued'] ?? 0),
                'in_payout' => (int) ($sums['in_registry'] ?? 0),
                'paid_total' => $paid,
            ])->save();

            return $balance;
        });
    }

    /** Read-only view of a balance (zeros if the payee has no ledger yet). */
    public function forUser(string $userId): PayeeBalance
    {
        return PayeeBalance::find($userId) ?? new PayeeBalance([
            'user_id' => $userId, 'available' => 0, 'in_payout' => 0, 'paid_total' => 0, 'payouts_suspended' => false,
        ]);
    }

    /** ADM-08: payouts are suspended with a reason visible to the psychologist (BR-PAYOUT-13). */
    public function suspend(User $payee, string $reason, User $actor): PayeeBalance
    {
        $balance = DB::transaction(function () use ($payee, $reason, $actor) {
            $balance = $this->lock($payee->id);
            $balance->forceFill([
                'payouts_suspended' => true, 'suspended_reason' => $reason, 'suspended_at' => now(), 'suspended_by' => $actor->id,
            ])->save();
            Audit::log('ADM-08', 'payouts.suspended', $payee, ['suspended' => true], $reason, $actor->id);

            return $balance;
        });
        $this->notifier->send($payee, 'payout.suspended', ['reason' => $reason], '/pro/payouts');

        return $balance;
    }

    public function resume(User $payee, User $actor): PayeeBalance
    {
        $balance = DB::transaction(function () use ($payee, $actor) {
            $balance = $this->lock($payee->id);
            $previous = $balance->suspended_reason;
            $balance->forceFill(['payouts_suspended' => false, 'suspended_reason' => null, 'suspended_at' => null, 'suspended_by' => null])->save();
            Audit::log('ADM-08', 'payouts.resumed', $payee, ['suspended' => false, 'previous_reason' => $previous], null, $actor->id);

            return $balance;
        });
        $this->notifier->send($payee, 'payout.resumed', [], '/pro/payouts');

        return $balance;
    }

    public static function toApi(PayeeBalance $b): array
    {
        return [
            'available' => (int) $b->available,
            'in_payout' => (int) $b->in_payout,
            'paid_total' => (int) $b->paid_total,
            'payouts_suspended' => (bool) $b->payouts_suspended,
            'suspended_reason' => $b->suspended_reason,
            'suspended_at' => $b->suspended_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Modules\Payouts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payouts\Models\Payout;
use App\Modules\Payouts\Models\PayoutCardBinding;
use App\Modules\Payouts\Services\PayeeBalanceService;
use App\Modules\Payouts\Services\PayoutCardService;
use App\Modules\Payouts\Services\PayoutRegistryService;
use App\Modules\Psychologists\Services\ActivityService;
use App\Support\Money;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;

/**
 * PRO-09: withdrawal — balance net of commission (DEC-20), automatic weekly payout to the self-employed card,
 * monthly supervision requirement (DEC-21, DEC-37), payout card, payout history.
 */
class ProPayoutsController extends Controller
{
    public function overview(Request $request, PayeeBalanceService $balances, ActivityService $activity)
    {
        $user = $request->user();
        $balance = $balances->forUser($user->id);
        $card = PayoutCardService::activeCard($user->id);
        $min = Settings::int('P-PAYOUT-MIN');
        $psychologist = $user->psychologist;
        $supervision = $psychologist
            ? $activity->currentStatus($psychologist)
            : ['month' => ActivityService::month()->toDateString(), 'applies' => false, 'met' => true, 'activity_status' => null, 'deadline' => ActivityService::month()->endOfMonth()->toIso8601String()];
        $allowed = $activity->payoutAllowed($user);
        $lastLine = Payout::where('user_id', $user->id)->latest()->with('paymentMethod')->first();

        return response()->json(['data' => [
            'balance' => PayeeBalanceService::toApi($balance),
            'commission_percent' => Settings::int('P-COMMISSION'),
            'min_amount' => $min,
            'next_payout_at' => PayoutRegistryService::nextPayoutAt()->toIso8601String(),
            'supervision' => [...$supervision, 'payout_allowed' => $allowed],
            'card' => $card?->toApi(),
            'checks' => [
                ['code' => 'supervision', 'ok' => $allowed, 'title' => 'Супервизия текущего месяца',
                    'hint' => $allowed ? ($supervision['applies'] ? 'Требование месяца выполнено' : 'Требование в этом месяце не действует') : 'Пройдите и оплатите супервизию до конца месяца — начисления сохраняются на балансе'],
                ['code' => 'card', 'ok' => $card !== null, 'title' => 'Карта для выплат',
                    'hint' => $card ? 'Карта '.$card->card_mask : 'Привяжите карту самозанятого'],
                ['code' => 'not_suspended', 'ok' => ! $balance->payouts_suspended, 'title' => 'Выплаты не приостановлены',
                    'hint' => $balance->payouts_suspended ? ($balance->suspended_reason ?: 'Приостановлены администратором') : null],
                ['code' => 'min_amount', 'ok' => (int) $balance->available >= $min, 'title' => 'Сумма не меньше '.Money::format($min),
                    'hint' => (int) $balance->available >= $min ? null : 'Меньшая сумма копится и уходит в следующую выплату'],
            ],
            'last_line' => $lastLine?->toApi(),
            'recent_payouts' => Payout::where('user_id', $user->id)
                ->whereIn('status', ['sent', 'unknown', 'paid', 'rejected'])
                ->with('paymentMethod')->latest()->limit(5)->get()->map(fn (Payout $p) => $p->toApi())->all(),
        ]]);
    }

    public function payouts(Request $request)
    {
        $page = Payout::where('user_id', $request->user()->id)
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->with('paymentMethod')
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Payout $p) => $p->toApi())->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function startCardBinding(Request $request, PayoutCardService $cards)
    {
        $binding = $cards->start($request->user());

        return response()->json(['data' => $this->binding($binding)], 201);
    }

    public function confirmCardBinding(Request $request, PayoutCardBinding $binding, PayoutCardService $cards)
    {
        abort_unless($binding->user_id === $request->user()->id, 404);
        $binding = $cards->confirm($binding);

        return response()->json(['data' => $this->binding($binding)]);
    }

    public function removeCard(Request $request, PayoutCardService $cards)
    {
        $cards->remove($request->user());

        return response()->json(['ok' => true]);
    }

    private function binding(PayoutCardBinding $binding): array
    {
        $card = $binding->payment_method_id ? PayoutCardService::activeCard($binding->user_id) : null;

        return [
            'id' => $binding->id,
            'status' => $binding->status,
            'confirmation_url' => $binding->status === 'pending' ? $binding->confirmation_url : null,
            'error_code' => $binding->error_code,
            'card' => $card?->toApi(),
        ];
    }
}

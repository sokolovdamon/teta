<?php

namespace App\Modules\Payouts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Payouts\Models\Accrual;
use App\Modules\Payouts\Models\PayeeBalance;
use App\Modules\Payouts\Models\Payout;
use App\Modules\Payouts\Models\PayoutRegistry;
use App\Modules\Payouts\Services\PayeeBalanceService;
use App\Modules\Payouts\Services\PayoutCardService;
use App\Modules\Payouts\Services\PayoutRegistryService;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Psychologists\Services\ActivityService;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * ADM-08: payout settings and auto-payout rules, weekly registries (approve, exclude a line, retry),
 * payees blocked by the supervision requirement, suspension of payouts. Every action is audited.
 */
class AdminPayoutsController extends Controller
{
    public const SETTINGS = ['P-PAYOUT-PERIOD', 'P-PAYOUT-MIN', 'P-PAYOUT-AUTO-APPROVE', 'P-PAYOUT-WEBHOOK-WAIT', 'P-COMMISSION', 'P-SUPERV-COMMISSION'];

    public function __construct(private PayoutRegistryService $registries) {}

    public function registries(Request $request)
    {
        $page = PayoutRegistry::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->with('approver')
            ->orderByDesc('period_start')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn (PayoutRegistry $r) => $this->registryRow($r))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function showRegistry(PayoutRegistry $registry)
    {
        $registry->load('approver');
        $lines = Payout::where('payout_registry_id', $registry->id)
            ->with(['user', 'paymentMethod'])
            ->orderByRaw("case status when 'in_registry' then 0 when 'unknown' then 1 when 'sent' then 2 when 'rejected' then 3 when 'blocked_supervision' then 4 when 'deferred' then 5 else 6 end")
            ->orderByDesc('amount')
            ->get();

        return response()->json(['data' => [
            ...$this->registryRow($registry),
            'lines' => $lines->map(fn (Payout $p) => [...$p->toApi(), 'payee' => $this->payeeRow($p->user)])->all(),
        ]]);
    }

    public function build(Request $request)
    {
        $registry = $this->registries->build(CarbonImmutable::now(), $request->user()->id);

        return response()->json(['data' => $this->registryRow($registry->fresh('approver'))], 201);
    }

    public function approve(Request $request, PayoutRegistry $registry)
    {
        $this->registries->approve($registry, $request->user());

        return response()->json(['data' => $this->registryRow($registry->fresh('approver'))]);
    }

    public function retry(Request $request, PayoutRegistry $registry)
    {
        $stats = $this->registries->retry($registry, $request->user());

        return response()->json(['data' => $this->registryRow($registry->fresh('approver')), 'stats' => $stats]);
    }

    public function exclude(Request $request, Payout $payout)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], ['reason.required' => 'Укажите причину исключения.']);
        $payout = $this->registries->exclude($payout, $data['reason'], $request->user());

        return response()->json(['data' => [...$payout->load(['user', 'paymentMethod'])->toApi(), 'payee' => $this->payeeRow($payout->user)]]);
    }

    public function settings()
    {
        $all = Settings::all();

        return response()->json(['data' => [
            'parameters' => collect(self::SETTINGS)->mapWithKeys(fn ($key) => [$key => $all[$key]])->all(),
            'next_run_at' => PayoutRegistryService::nextPayoutAt()->toIso8601String(),
            'payout_weekday' => PayoutRegistryService::payoutWeekday(),
            'edit_section' => 'ADM-26',
        ]]);
    }

    /** Payees whose weekly payout is blocked by the monthly supervision requirement (DEC-37 p. 8). */
    public function blocked(ActivityService $activity)
    {
        $balances = PayeeBalance::all()->keyBy('user_id');
        $rows = Psychologist::with('user.roles')->where('qualification_status', 'approved')->whereNotNull('qualified_at')->get()
            ->filter(function (Psychologist $p) use ($activity) {
                if (! $p->user) {
                    return false;
                }
                $p->user->setRelation('psychologist', $p);

                return ! $activity->payoutAllowed($p->user);
            })
            ->map(function (Psychologist $p) use ($balances, $activity) {
                $balance = $balances->get($p->user_id);
                $lastBlocked = Payout::where('user_id', $p->user_id)->where('status', 'blocked_supervision')->latest()->first();

                return [
                    'psychologist_id' => $p->id,
                    'payee' => $this->payeeRow($p->user),
                    'activity_status' => $p->activity_status,
                    'requirement' => $activity->currentStatus($p),
                    'available' => (int) ($balance?->available ?? 0),
                    'last_blocked_at' => $lastBlocked?->created_at?->toIso8601String(),
                    'last_blocked_amount' => $lastBlocked ? (int) $lastBlocked->amount : null,
                ];
            })
            ->sortByDesc('available')
            ->values();

        return response()->json(['data' => $rows->all()]);
    }

    public function payees(Request $request)
    {
        $page = PayeeBalance::query()
            ->with('user')
            ->when($request->boolean('suspended'), fn ($q) => $q->where('payouts_suspended', true))
            ->when($request->query('q'), fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('email', 'ilike', "%{$v}%")->orWhere('name', 'ilike', "%{$v}%")->orWhere('last_name', 'ilike', "%{$v}%")))
            ->orderByDesc('payouts_suspended')->orderByDesc('available')
            ->paginate(min((int) $request->integer('per_page', 30), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn (PayeeBalance $b) => [
                'payee' => $this->payeeRow($b->user),
                ...PayeeBalanceService::toApi($b),
                'has_card' => PayoutCardService::activeCard($b->user_id) !== null,
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function payee(User $user, PayeeBalanceService $balances)
    {
        $card = PayoutCardService::activeCard($user->id);

        return response()->json(['data' => [
            'payee' => $this->payeeRow($user),
            'balance' => PayeeBalanceService::toApi($balances->forUser($user->id)),
            'card' => $card?->toApi(),
            'accruals' => Accrual::where('user_id', $user->id)->latest('occurred_at')->limit(50)->get()->map(fn (Accrual $a) => [
                'id' => $a->id, 'kind' => $a->kind, 'kind_label' => Accrual::KIND_LABELS[$a->kind] ?? $a->kind,
                'status' => $a->status, 'status_label' => Accrual::STATUS_LABELS[$a->status] ?? $a->status,
                'amount' => (int) $a->amount, 'reversed_amount' => (int) $a->reversed_amount, 'net' => $a->net(),
                'reason' => $a->reason, 'occurred_at' => $a->occurred_at?->toIso8601String(),
            ])->all(),
            'payouts' => Payout::where('user_id', $user->id)->with('paymentMethod')->latest()->limit(30)->get()->map(fn (Payout $p) => $p->toApi())->all(),
        ]]);
    }

    public function suspend(Request $request, User $user, PayeeBalanceService $balances)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], ['reason.required' => 'Укажите причину приостановки.']);
        $balance = $balances->suspend($user, $data['reason'], $request->user());

        return response()->json(['data' => ['payee' => $this->payeeRow($user), ...PayeeBalanceService::toApi($balance)]]);
    }

    public function resume(Request $request, User $user, PayeeBalanceService $balances)
    {
        $balance = $balances->resume($user, $request->user());

        return response()->json(['data' => ['payee' => $this->payeeRow($user), ...PayeeBalanceService::toApi($balance)]]);
    }

    private function registryRow(PayoutRegistry $r): array
    {
        return [
            'id' => $r->id,
            'period_start' => $r->period_start?->toDateString(),
            'period_end' => $r->period_end?->toDateString(),
            'status' => $r->status,
            'status_label' => PayoutRegistry::STATUS_LABELS[$r->status] ?? $r->status,
            'auto_approve' => (bool) $r->auto_approve,
            'approved_at' => $r->approved_at?->toIso8601String(),
            'approved_by' => $r->approver ? $r->approver->fullName() : null,
            'sent_at' => $r->sent_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
            'summary' => $this->registries->summary($r),
        ];
    }

    private function payeeRow(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->fullName(), 'email' => $user->email] : null;
    }
}

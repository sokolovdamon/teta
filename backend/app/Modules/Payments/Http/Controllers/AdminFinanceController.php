<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Audit;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Payments\Http\PaymentsPresenter;
use App\Modules\Payments\Models\ChargeComplaint;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\GiftCertificate;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Services\BalanceService;
use App\Modules\Payments\Services\ComplaintService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\WithdrawalService;
use App\Support\Events\Outbox;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ADM-07 finance — what PAY knows: turnover by purpose, refunds, client balances, certificates, retained session
 * revenue and the platform commission estimated as retained money minus 70 % of the full price (DEC-20, DEC-57);
 * the authoritative accruals live in PAYOUT. Every admin action is written to the audit log.
 */
class AdminFinanceController extends Controller
{
    public function __construct(private PaymentService $payments, private WithdrawalService $withdrawals) {}

    public function summary(Request $request): JsonResponse
    {
        $tz = (string) config('platform.timezone');
        $from = $request->query('from') ? CarbonImmutable::parse((string) $request->query('from'), $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfMonth();
        $to = $request->query('to') ? CarbonImmutable::parse((string) $request->query('to'), $tz)->endOfDay() : CarbonImmutable::now($tz)->endOfMonth();

        $turnover = Payment::whereIn('status', ['succeeded', 'partially_refunded', 'refunded'])
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('purpose, count(*) as count, coalesce(sum(amount), 0) as amount')
            ->groupBy('purpose')->get()
            ->map(fn ($r) => ['purpose' => $r->purpose, 'label' => PaymentsPresenter::PURPOSE_LABELS[$r->purpose] ?? $r->purpose, 'count' => (int) $r->count, 'amount' => (int) $r->amount])->values();
        $refunds = (int) PaymentRefund::where('status', 'succeeded')->whereBetween('created_at', [$from, $to])->sum('amount');
        $credits = ClientBalanceOperation::where('type', 'credit')->where('status', 'credited')->whereBetween('created_at', [$from, $to])
            ->selectRaw('reason, coalesce(sum(amount), 0) as amount')->groupBy('reason')->pluck('amount', 'reason')->map(fn ($v) => (int) $v);
        $spent = (int) ClientBalanceOperation::where('type', 'spend')->where('status', 'spent')->whereBetween('created_at', [$from, $to])->sum('amount');

        $commission = Settings::int('P-COMMISSION');
        $retained = TherapySession::query()
            ->whereNull('corporate_participation_id')
            ->whereBetween('starts_at', [$from, $to])
            ->where(fn ($q) => $q->whereIn('status', [TherapySession::HELD, TherapySession::CLIENT_NO_SHOW])
                ->orWhere(fn ($w) => $w->where('status', TherapySession::CANCELLED_BY_CLIENT)->where('cancel_kind', 'late_cancel')))
            ->selectRaw('count(*) as count,
                coalesce(sum(amount_charged - balance_refunded), 0) as retained,
                coalesce(sum(price * (100 - coalesce((params->>\'P-COMMISSION\')::int, ?)) / 100), 0) as psychologist_share', [$commission])
            ->first();

        return response()->json(['data' => [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'turnover' => $turnover,
            'turnover_total' => (int) $turnover->sum('amount'),
            'refunds_to_card' => $refunds,
            'balance' => [
                'credited' => $credits,
                'credited_total' => (int) $credits->sum(),
                'spent' => $spent,
                'outstanding' => (int) DB::table('client_balances')->sum('available'),
                'outstanding_certificate' => (int) DB::table('client_balances')->sum('certificate_available'),
            ],
            'sessions' => [
                'count' => (int) $retained->count,
                'retained' => (int) $retained->retained,
                'psychologist_share' => (int) $retained->psychologist_share,
                'platform_commission' => (int) $retained->retained - (int) $retained->psychologist_share,
            ],
            'certificates' => [
                'sold' => GiftCertificate::whereIn('status', ['paid', 'activated', 'expired'])->whereBetween('updated_at', [$from, $to])->count(),
                'sold_amount' => (int) GiftCertificate::whereIn('status', ['paid', 'activated', 'expired'])->whereBetween('updated_at', [$from, $to])->sum('nominal'),
                'activated' => GiftCertificate::where('status', 'activated')->whereBetween('activated_at', [$from, $to])->count(),
            ],
            'complaints' => [
                'open' => ChargeComplaint::whereIn('status', ComplaintService::OPEN)->count(),
                'overdue' => ChargeComplaint::whereIn('status', ComplaintService::OPEN)->where('due_date', '<', CarbonImmutable::now($tz)->toDateString())->count(),
            ],
            'failed_charges' => ChargeTask::where('status', 'retry_wait')->count(),
        ]]);
    }

    public function payments(Request $request): JsonResponse
    {
        $page = Payment::query()->with('user')
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', (string) $v)))
            ->when($request->query('purpose'), fn ($q, $v) => $q->whereIn('purpose', explode(',', (string) $v)))
            ->when($request->query('date_from'), fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->when($request->query('q'), fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('email', 'ilike', "%{$v}%")->orWhere('name', 'ilike', "%{$v}%")->orWhere('last_name', 'ilike', "%{$v}%")))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 30), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($p) => PaymentsPresenter::payment($p, true))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function payment(Payment $payment): JsonResponse
    {
        return response()->json(['data' => PaymentsPresenter::payment($payment, true)]);
    }

    /** Manual refund to the card with a reason (ST-03 succeeded → partially_refunded | refunded). */
    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $refund = $this->payments->refundToCard($payment, (int) $data['amount'], null, $data['reason'], $request->user()->id);
        Audit::log('ADM-07', 'payment.refund', $payment, ['amount' => (int) $data['amount'], 'refund_id' => $refund->id, 'status' => $refund->status], $data['reason']);

        if ($refund->status === 'succeeded' && ($session = PaymentsPresenter::session($payment))) {
            DB::transaction(function () use ($session, $refund, $request) {
                $s = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
                $amount = min($refund->amount, $s->retainedAmount());
                if ($amount > 0) {
                    $s->forceFill(['balance_refunded' => (int) $s->balance_refunded + $amount])->save();
                    Outbox::record('pay.refund.manual', $s, [
                        'session_id' => $s->id,
                        'refund_amount' => $amount,
                        'share_percent' => round($amount * 100 / max(1, (int) $s->amount_charged), 4),
                    ], $request->user()->id);
                }
            });
        }

        return response()->json(['data' => PaymentsPresenter::payment($payment->fresh(), true), 'refund_status' => $refund->status]);
    }

    public function refunds(Request $request): JsonResponse
    {
        $page = PaymentRefund::query()->with('payment.user')
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', (string) $v)))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 30), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn (PaymentRefund $r) => [
                'id' => $r->id,
                'amount' => (int) $r->amount,
                'status' => $r->status,
                'reason' => $r->reason,
                'error_code' => $r->error_code,
                'source_type' => $r->source_type,
                'payment' => ['id' => $r->payment_id, 'amount' => (int) $r->payment?->amount, 'purpose' => $r->payment?->purpose, 'card_mask' => $r->payment?->card_mask],
                'user' => $r->payment?->user?->only(['id', 'name', 'last_name', 'email']),
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function balanceOperations(Request $request): JsonResponse
    {
        $page = ClientBalanceOperation::query()->with('user')
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', (string) $v)))
            ->when($request->query('reason'), fn ($q, $v) => $q->where('reason', $v))
            ->when($request->query('q'), fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('email', 'ilike', "%{$v}%")))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 30), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn (ClientBalanceOperation $op) => [
                ...BalanceService::toApi($op),
                'user' => $op->user?->only(['id', 'name', 'last_name', 'email']),
                'meta' => $op->meta,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function resolveWithdrawal(Request $request, ClientBalanceOperation $operation): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['withdrawn', 'cancel'])],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $op = $this->withdrawals->resolve($operation, $request->user(), $data['action'], $data['reason']);

        return response()->json(['data' => BalanceService::toApi($op)]);
    }

    public function chargeTasks(Request $request): JsonResponse
    {
        $page = ChargeTask::query()->with(['session.psychologist'])
            ->whereIn('status', explode(',', (string) $request->query('status', 'retry_wait,failed_final,in_progress')))
            ->orderByDesc('updated_at')
            ->paginate(min((int) $request->integer('per_page', 30), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn (ChargeTask $t) => [
                'id' => $t->id,
                'status' => $t->status,
                'amount' => (int) $t->amount,
                'balance_part' => (int) $t->balance_part,
                'attempts' => (int) $t->attempts,
                'due_at' => $t->due_at?->toIso8601String(),
                'deadline_at' => $t->deadline_at?->toIso8601String(),
                'next_attempt_at' => $t->next_attempt_at?->toIso8601String(),
                'last_error_category' => $t->last_error_category,
                'last_error_code' => $t->last_error_code,
                'session' => $t->session ? ['id' => $t->session->id, 'starts_at' => $t->session->starts_at?->toIso8601String(), 'psychologist' => $t->session->psychologist?->fullName()] : null,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}

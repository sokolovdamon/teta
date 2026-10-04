<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Payments\Http\PaymentsPresenter;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\GiftCertificate;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\BalanceService;
use App\Modules\Payments\Services\CardService;
use App\Modules\Payments\Services\ChargeService;
use App\Modules\Payments\Services\GiftCertificateService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CL-07: balance, cards, charges awaiting payment, history with receipts, withdrawal of the remainder. */
class ClientPaymentController extends Controller
{
    public function __construct(
        private BalanceService $balance,
        private CardService $cards,
        private ChargeService $charges,
        private PaymentService $payments,
        private WithdrawalService $withdrawals,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $tasks = ChargeTask::where('user_id', $user->id)->whereIn('status', ['scheduled', 'retry_wait', 'in_progress'])->orderBy('due_at')->get();
        $sessions = TherapySession::with('psychologist')->whereIn('id', $tasks->pluck('therapy_session_id'))->get()->keyBy('id');
        $withdrawal = ClientBalanceOperation::where('user_id', $user->id)->where('type', 'withdraw')
            ->whereIn('status', ['withdraw_reserved', 'withdraw_processing', 'withdraw_review'])->latest()->first();

        return response()->json(['data' => [
            'balance' => $this->balance->summary($user->id),
            'cards' => $this->cards->active($user->id)->map->toApi()->values(),
            'charges' => $tasks->map(fn (ChargeTask $t) => [
                'id' => $t->id,
                'status' => $t->status,
                'amount' => (int) $t->amount,
                'balance_part' => (int) $t->balance_part,
                'due_at' => $t->due_at?->toIso8601String(),
                'deadline_at' => $t->deadline_at?->toIso8601String(),
                'last_error_category' => $t->last_error_category,
                'last_error_message' => $t->last_error_code ? PaymentService::declineMessage($t->last_error_code) : null,
                'can_pay' => $t->status === 'retry_wait',
                'session' => ($s = $sessions[$t->therapy_session_id] ?? null) ? [
                    'id' => $s->id,
                    'starts_at' => $s->starts_at?->toIso8601String(),
                    'psychologist' => $s->psychologist?->fullName(),
                ] : null,
            ])->values(),
            'withdrawal' => $withdrawal ? BalanceService::toApi($withdrawal) : null,
            'certificates' => GiftCertificate::where('activated_by', $user->id)->orderByDesc('activated_at')->get()
                ->map(fn ($c) => GiftCertificateService::toApi($c))->values(),
            'timezone' => $user->timezone,
        ]]);
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $payments = Payment::where('user_id', $user->id)->whereNotIn('status', ['created'])
            ->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $payments->map(fn ($p) => PaymentsPresenter::payment($p))->values()]);
    }

    public function balanceOperations(Request $request): JsonResponse
    {
        $ops = ClientBalanceOperation::where('user_id', $request->user()->id)->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $ops->map(fn ($op) => BalanceService::toApi($op))->values()]);
    }

    /** Public status of a payment for /pay/return; an open payment is checked with the gateway (lost webhook). */
    public function status(Payment $payment): JsonResponse
    {
        if (in_array($payment->status, ['requires_3ds', 'unknown'], true) && $payment->updated_at < now()->subSeconds(5)) {
            $payment = $this->payments->resolve($payment);
        }

        return response()->json(['data' => PaymentsPresenter::publicStatus($payment->fresh())]);
    }

    /** CL-07: pay a failed charge with another card (payment with the payer, 3-D Secure). */
    public function payCharge(Request $request, ChargeTask $task): JsonResponse
    {
        abort_unless($task->user_id === $request->user()->id, 404);
        $saveCard = $request->boolean('save_card', true);
        $payment = $this->charges->payWithPayer($task, $request->user(), $saveCard);

        return response()->json([
            'paid' => $payment === null,
            'payment_id' => $payment?->id,
            'confirmation_url' => $payment?->confirmation_url,
        ]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $op = $this->withdrawals->request($request->user());

        return response()->json(['data' => BalanceService::toApi($op->fresh()), 'balance' => $this->balance->summary($request->user()->id)], 201);
    }
}

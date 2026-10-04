<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Booking\Http\SessionPresenter;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Services\CancellationService;
use App\Modules\Booking\Services\OutcomeService;
use App\Modules\Payments\Models\ChargeComplaint;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\BalanceService;
use App\Support\StateMachine\StateTransition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** ADM-04: sessions and the session log — list with filters, card with history, outcome correction, platform cancel. */
class AdminSessionController extends Controller
{
    public function __construct(
        private SessionPresenter $presenter,
        private OutcomeService $outcomes,
        private CancellationService $cancellations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = TherapySession::query()->with(['psychologist', 'client'])
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', (string) $v)))
            ->when($request->query('psychologist_id'), fn ($q, $v) => $q->where('psychologist_id', $v))
            ->when($request->query('client_id'), fn ($q, $v) => $q->where('client_id', $v))
            ->when($request->query('date_from'), fn ($q, $v) => $q->where('starts_at', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->where('starts_at', '<=', $v.' 23:59:59'))
            ->when($request->query('q'), function ($q, $v) {
                $q->where(function ($w) use ($v) {
                    $w->whereHas('client', fn ($c) => $c->where('email', 'ilike', "%{$v}%")->orWhere('name', 'ilike', "%{$v}%")->orWhere('last_name', 'ilike', "%{$v}%"))
                        ->orWhereHas('psychologist', fn ($p) => $p->where('first_name', 'ilike', "%{$v}%")->orWhere('last_name', 'ilike', "%{$v}%"));
                });
            })
            ->when($request->boolean('needs_attention'), fn ($q) => $q->where(fn ($w) => $w->where('status', TherapySession::IN_PROGRESS)->orWhere('client_choice', 'pending')))
            ->orderByDesc('starts_at');
        $page = $query->paginate(min((int) $request->integer('per_page', 30), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($s) => $this->presenter->forAdmin($s))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(TherapySession $session): JsonResponse
    {
        $session->load(['psychologist', 'client', 'chargeTask']);
        $task = $session->chargeTask;
        $payments = Payment::where(fn ($q) => $q->where('payable_type', $session->getMorphClass())->where('payable_id', $session->id))
            ->when($session->payment_id, fn ($q) => $q->orWhere('id', $session->payment_id))
            ->orderBy('created_at')->get();

        $history = StateTransition::query()
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('model_type', $session->getMorphClass())->where('model_id', $session->id))
                ->when($task, fn ($w) => $w->orWhere(fn ($x) => $x->where('model_type', (new ChargeTask)->getMorphClass())->where('model_id', $task->id))))
            ->orderBy('created_at')->get()
            ->map(fn (StateTransition $t) => [
                'entity' => $t->model_type,
                'field' => $t->field,
                'from' => $t->from,
                'to' => $t->to,
                'event' => $t->event,
                'reason' => $t->reason,
                'context' => $t->context,
                'actor' => $t->actor_id ? User::withTrashed()->find($t->actor_id)?->only(['id', 'name', 'last_name']) : null,
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => $this->presenter->forAdmin($session),
            'history' => $history->values(),
            'charge_task' => $task ? [
                'id' => $task->id, 'status' => $task->status, 'amount' => $task->amount, 'balance_part' => $task->balance_part,
                'attempts' => $task->attempts, 'due_at' => $task->due_at?->toIso8601String(), 'deadline_at' => $task->deadline_at?->toIso8601String(),
                'next_attempt_at' => $task->next_attempt_at?->toIso8601String(), 'last_error_category' => $task->last_error_category, 'last_error_code' => $task->last_error_code,
                'attempts_log' => $task->attemptsLog()->get(['number', 'result', 'error_category', 'error_code', 'created_at']),
            ] : null,
            'payments' => $payments->map(fn (Payment $p) => [
                'id' => $p->id, 'purpose' => $p->purpose, 'amount' => $p->amount, 'refunded_amount' => $p->refunded_amount, 'status' => $p->status,
                'card_mask' => $p->card_mask, 'with_payer' => $p->with_payer, 'error_code' => $p->error_code, 'paid_at' => $p->paid_at?->toIso8601String(),
            ])->values(),
            'balance_operations' => ClientBalanceOperation::where('source_type', $session->getMorphClass())->where('source_id', $session->id)->orderBy('created_at')->get()
                ->map(fn ($op) => BalanceService::toApi($op))->values(),
            'complaints' => ChargeComplaint::where('therapy_session_id', $session->id)->get(['id', 'status', 'due_date', 'refund_amount']),
            'rescheduled_to' => TherapySession::where('rescheduled_from_id', $session->id)->value('id'),
        ]);
    }

    public function outcome(Request $request, TherapySession $session): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(OutcomeService::OUTCOMES)],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $s = $this->outcomes->setByAdmin($session, $request->user(), $data['outcome'], $data['reason']);

        return response()->json(['data' => $this->presenter->forAdmin($s->fresh(['psychologist', 'client']))]);
    }

    /** Cancel by the platform (cancelled_by_system), paid money fully credited to the client's balance. */
    public function cancel(Request $request, TherapySession $session): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $s = $this->cancellations->cancelBySystem($session, 'admin', $data['reason'], $request->user()->id);
        abort_unless($s, 409, 'Сессию в этом статусе нельзя отменить.');
        Audit::log('ADM-04', 'session.cancelled', $s, ['status' => $s->status], $data['reason']);

        return response()->json(['data' => $this->presenter->forAdmin($s->fresh(['psychologist', 'client']))]);
    }
}

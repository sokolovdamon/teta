<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Http\SessionPresenter;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Booking\Services\CancellationService;
use App\Modules\Booking\Services\OutcomeService;
use App\Modules\Psychologists\Models\Psychologist;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** PRO-04: the psychologist's calendar of bookings, session card, outcome, cancel and reschedule. */
class ProSessionController extends Controller
{
    public function __construct(
        private BookingService $booking,
        private CancellationService $cancellations,
        private OutcomeService $outcomes,
        private SessionPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $p = $this->psychologist($request);
        $tz = $p->timezone ?: config('platform.timezone');
        $from = $request->query('from') ? CarbonImmutable::parse($request->query('from')) : CarbonImmutable::now($tz)->startOfWeek();
        $to = $request->query('to') ? CarbonImmutable::parse($request->query('to')) : $from->addWeek();
        $sessions = TherapySession::query()->with(['psychologist', 'client'])
            ->where('psychologist_id', $p->id)
            ->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
            ->when($request->query('status'), fn ($q, $status) => $q->whereIn('status', explode(',', (string) $status)))
            ->when(! $request->boolean('include_cancelled'), fn ($q) => $q->whereNotIn('status', [TherapySession::CANCELLED_BY_CLIENT, TherapySession::CANCELLED_BY_PSY, TherapySession::CANCELLED_BY_SYSTEM]))
            ->orderBy('starts_at')
            ->limit(500)->get();

        $awaitingOutcome = TherapySession::where('psychologist_id', $p->id)->where('status', TherapySession::IN_PROGRESS)->count();

        return response()->json([
            'data' => $sessions->map(fn ($s) => $this->presenter->forPsychologist($s))->values(),
            'timezone' => $tz,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'awaiting_outcome' => $awaitingOutcome,
        ]);
    }

    public function show(Request $request, TherapySession $session): JsonResponse
    {
        $this->own($request, $session);

        return response()->json(['data' => $this->presenter->forPsychologist($session->load(['psychologist', 'client']))]);
    }

    public function outcome(Request $request, TherapySession $session): JsonResponse
    {
        $this->own($request, $session);
        $data = $request->validate(['outcome' => ['required', Rule::in(OutcomeService::PSYCHOLOGIST_OUTCOMES)]]);
        $s = $this->outcomes->setByPsychologist($session, $request->user(), $data['outcome']);

        return response()->json(['data' => $this->presenter->forPsychologist($s->fresh(['psychologist', 'client']))]);
    }

    public function cancel(Request $request, TherapySession $session): JsonResponse
    {
        $this->own($request, $session);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $s = $this->cancellations->cancelByPsychologist($session, $request->user(), $data['reason']);

        return response()->json(['data' => $this->presenter->forPsychologist($s->fresh(['psychologist', 'client']))]);
    }

    public function rescheduleSlots(Request $request, TherapySession $session): JsonResponse
    {
        $this->own($request, $session);
        $from = $request->query('from') ? CarbonImmutable::parse($request->query('from')) : null;
        $to = $request->query('to') ? CarbonImmutable::parse($request->query('to')) : null;

        return response()->json($this->booking->rescheduleSlots($session, $from, $to));
    }

    public function reschedule(Request $request, TherapySession $session): JsonResponse
    {
        $this->own($request, $session);
        $data = $request->validate(['starts_at' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $s = $this->booking->reschedule($session, $request->user(), CarbonImmutable::parse($data['starts_at']), 'psychologist', $data['reason'] ?? null);

        return response()->json(['data' => $this->presenter->forPsychologist($s->fresh(['psychologist', 'client']))]);
    }

    private function psychologist(Request $request): Psychologist
    {
        $p = Psychologist::where('user_id', $request->user()->id)->first();
        abort_unless($p, 404, 'Профиль психолога не найден.');

        return $p;
    }

    private function own(Request $request, TherapySession $session): void
    {
        abort_unless($session->psychologist_id === $this->psychologist($request)->id, 404);
    }
}

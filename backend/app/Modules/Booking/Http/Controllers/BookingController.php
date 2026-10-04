<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Booking\Http\SessionPresenter;
use App\Modules\Booking\Models\BookingIntent;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Booking\Services\CancellationService;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Schedule\Models\SlotHold;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Client side of BOOK: quote, holds, booking, CL-03 sessions, reschedule, cancel, choice, pair partner, change of psychologist. */
class BookingController extends Controller
{
    public function __construct(
        private BookingService $booking,
        private CancellationService $cancellations,
        private SessionPresenter $presenter,
    ) {}

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'psychologist_id' => ['required', 'uuid', 'exists:psychologists,id'],
            'format' => ['nullable', Rule::in(['individual', 'pair'])],
            'starts_at' => ['required', 'date'],
            'promo_code' => ['nullable', 'string', 'max:64'],
        ]);
        $quote = $this->booking->quote(
            $request->user('sanctum'),
            Psychologist::findOrFail($data['psychologist_id']),
            $data['format'] ?? 'individual',
            CarbonImmutable::parse($data['starts_at']),
            $data['promo_code'] ?? null,
        );

        return response()->json(['data' => $quote]);
    }

    public function hold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'psychologist_id' => ['required', 'uuid', 'exists:psychologists,id'],
            'format' => ['nullable', Rule::in(['individual', 'pair'])],
            'starts_at' => ['required', 'date'],
            'guest_token' => ['nullable', 'string', 'max:64'],
        ]);
        $hold = $this->booking->hold(
            $request->user('sanctum'),
            Psychologist::findOrFail($data['psychologist_id']),
            $data['format'] ?? 'individual',
            CarbonImmutable::parse($data['starts_at']),
            $data['guest_token'] ?? null,
        );

        return response()->json(['data' => [
            'id' => $hold->id,
            'starts_at' => $hold->starts_at->toIso8601String(),
            'expires_at' => $hold->expires_at->toIso8601String(),
            'guest_token' => $hold->guest_token,
        ]], 201);
    }

    public function releaseHold(Request $request, SlotHold $hold): JsonResponse
    {
        $this->booking->releaseHold($hold, $request->user('sanctum'), $request->input('guest_token') ?? $request->query('guest_token'));

        return response()->json(['ok' => true]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'psychologist_id' => ['required', 'uuid', 'exists:psychologists,id'],
            'format' => ['nullable', Rule::in(['individual', 'pair'])],
            'starts_at' => ['required', 'date', 'after:now'],
            'promo_code' => ['nullable', 'string', 'max:64'],
            'hold_id' => ['nullable', 'uuid'],
            'guest_token' => ['nullable', 'string', 'max:64'],
            'client_request_ids' => ['nullable', 'array', 'max:20'],
            'client_request_ids.*' => ['uuid', 'exists:client_requests,id'],
            'partner_email' => ['nullable', 'email', 'max:255'],
            'source' => ['nullable', Rule::in(['wizard', 'catalog', 'corporate'])],
            'pay_with' => ['nullable', Rule::in(['saved_card', 'new_card'])],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $result = $this->booking->book($user, $data);

        if (isset($result['session'])) {
            return response()->json(['data' => $this->presenter->forClient($result['session']->fresh(['psychologist', 'chargeTask']), $user)], 201);
        }

        return response()->json([
            'data' => null,
            'intent' => $result['intent']->toApi(),
            'confirmation_url' => $result['confirmation_url'] ?? null,
        ], 202);
    }

    public function intent(Request $request, BookingIntent $intent): JsonResponse
    {
        abort_unless($intent->client_id === $request->user()->id, 404);
        $result = $this->booking->intentResult($intent);

        return response()->json([
            'intent' => $intent->toApi(),
            'session' => isset($result['session']) ? $this->presenter->forClient($result['session'], $request->user()) : null,
            'confirmation_url' => $result['confirmation_url'] ?? null,
        ]);
    }

    /** CL-03: upcoming and past sessions of the client (and sessions where the user is the pair partner). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = $request->query('scope', 'upcoming');
        $query = TherapySession::query()->with(['psychologist', 'chargeTask'])
            ->where(fn ($q) => $q->where('client_id', $user->id)->orWhere('partner_user_id', $user->id));
        if ($scope === 'past') {
            $query->whereNotIn('status', [TherapySession::BOOKED, TherapySession::PAID, TherapySession::IN_PROGRESS])
                ->where(fn ($q) => $q->where('client_choice', '!=', 'pending')->orWhereNull('client_choice'))
                ->orderByDesc('starts_at');
        } else {
            $query->where(fn ($q) => $q->whereIn('status', [TherapySession::BOOKED, TherapySession::PAID, TherapySession::IN_PROGRESS])
                ->orWhere('client_choice', 'pending'))
                ->orderBy('starts_at');
        }
        $sessions = $query->limit(100)->get();

        $invitations = TherapySession::query()->with('psychologist')
            ->whereRaw('lower(partner_email) = ?', [mb_strtolower($user->email)])
            ->whereNull('partner_user_id')
            ->where('client_id', '!=', $user->id)
            ->whereIn('status', [TherapySession::BOOKED, TherapySession::PAID])
            ->where('starts_at', '>', now())
            ->get()
            ->map(fn (TherapySession $s) => [
                'id' => $s->id,
                'starts_at' => $s->starts_at->toIso8601String(),
                'psychologist' => $s->psychologist?->fullName(),
                'inviter' => User::find($s->client_id)?->name,
            ]);

        return response()->json([
            'data' => $sessions->map(fn ($s) => $this->presenter->forClient($s, $user))->values(),
            'invitations' => $invitations->values(),
            'timezone' => $user->timezone,
        ]);
    }

    public function show(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorizeParticipant($request->user(), $session);

        return response()->json(['data' => $this->presenter->forClient($session->load(['psychologist', 'chargeTask']), $request->user())]);
    }

    public function rescheduleSlots(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorizeClient($request->user(), $session);
        $from = $request->query('from') ? CarbonImmutable::parse($request->query('from')) : null;
        $to = $request->query('to') ? CarbonImmutable::parse($request->query('to')) : null;

        return response()->json($this->booking->rescheduleSlots($session, $from, $to));
    }

    public function reschedule(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorizeClient($request->user(), $session);
        $data = $request->validate(['starts_at' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $s = $this->booking->reschedule($session, $request->user(), CarbonImmutable::parse($data['starts_at']), 'client', $data['reason'] ?? null);

        return response()->json(['data' => $this->presenter->forClient($s->fresh(['psychologist', 'chargeTask']), $request->user())]);
    }

    public function cancel(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorizeClient($request->user(), $session);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000'], 'confirm_late' => ['nullable', 'boolean']]);
        $result = $this->cancellations->cancelByClient($session, $request->user(), $data['reason'] ?? null, (bool) ($data['confirm_late'] ?? false));

        return response()->json([
            'data' => $this->presenter->forClient($result['session']->fresh(['psychologist', 'chargeTask']), $request->user()),
            'kind' => $result['kind'],
            'refund_amount' => $result['refund_amount'],
            'retained_amount' => $result['retained_amount'],
        ]);
    }

    /** After a psychologist's cancel / no-show or a technical issue: refund to the balance or free reschedule. */
    public function choose(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorizeClient($request->user(), $session);
        $data = $request->validate([
            'choice' => ['required', Rule::in(['refund', 'reschedule'])],
            'starts_at' => ['required_if:choice,reschedule', 'nullable', 'date'],
        ]);
        $result = $this->cancellations->choose($session, $request->user(), $data['choice'], isset($data['starts_at']) ? CarbonImmutable::parse($data['starts_at']) : null);

        return response()->json(['data' => $this->presenter->forClient($result->fresh(['psychologist', 'chargeTask']), $request->user())]);
    }

    public function acceptPartner(Request $request, TherapySession $session): JsonResponse
    {
        $s = $this->booking->acceptPartner($session, $request->user());

        return response()->json(['data' => $this->presenter->forClient($s->fresh(['psychologist']), $request->user())]);
    }

    public function myPsychologists(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->booking->myPsychologists($request->user())]);
    }

    public function changePsychologist(Request $request): JsonResponse
    {
        $data = $request->validate([
            'psychologist_id' => ['required', 'uuid', 'exists:psychologists,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $result = $this->cancellations->changePsychologist($request->user(), Psychologist::withTrashed()->findOrFail($data['psychologist_id']), $data['reason'] ?? null);

        return response()->json($result);
    }

    private function authorizeParticipant(User $user, TherapySession $s): void
    {
        abort_unless($s->client_id === $user->id || $s->partner_user_id === $user->id, 404);
    }

    private function authorizeClient(User $user, TherapySession $s): void
    {
        abort_unless($s->client_id === $user->id, 404);
    }
}

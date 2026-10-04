<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Models\SessionTimeRequest;
use App\Modules\Booking\Services\TimeRequestService;
use App\Modules\Psychologists\Models\Psychologist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "Нет подходящего времени" (DEC-28): client requests and the psychologist's inbox (PRO-04). */
class TimeRequestController extends Controller
{
    public function __construct(private TimeRequestService $requests) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = SessionTimeRequest::with('psychologist')->where('client_id', $user->id)->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => $items->map(fn ($r) => $this->requests->toApi($r, $user->timezone))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'psychologist_id' => ['required', 'uuid', 'exists:psychologists,id'],
            'format' => ['nullable', Rule::in(['individual', 'pair'])],
            'preferred' => ['required', 'array', 'min:1', 'max:14'],
            'preferred.*.weekday' => ['nullable', 'integer', 'between:1,7'],
            'preferred.*.date' => ['nullable', 'date'],
            'preferred.*.from' => ['required', 'date_format:H:i'],
            'preferred.*.to' => ['required', 'date_format:H:i'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $r = $this->requests->create($request->user(), $data);

        return response()->json(['data' => $this->requests->toApi($r, $request->user()->timezone)], 201);
    }

    public function cancel(Request $request, SessionTimeRequest $timeRequest): JsonResponse
    {
        $r = $this->requests->cancel($timeRequest, $request->user());

        return response()->json(['data' => $this->requests->toApi($r, $request->user()->timezone)]);
    }

    public function inbox(Request $request): JsonResponse
    {
        $p = $this->psychologist($request);
        $items = SessionTimeRequest::with('psychologist')->where('psychologist_id', $p->id)
            ->when($request->query('status', 'active') === 'active', fn ($q) => $q->whereIn('status', ['open', 'offered']))
            ->orderByDesc('created_at')->limit(100)->get();

        return response()->json([
            'data' => $items->map(fn ($r) => $this->requests->toApi($r, $p->timezone, true))->values(),
            'open' => SessionTimeRequest::where('psychologist_id', $p->id)->where('status', 'open')->count(),
        ]);
    }

    public function offer(Request $request, SessionTimeRequest $timeRequest): JsonResponse
    {
        $p = $this->psychologist($request);
        $data = $request->validate([
            'slots' => ['nullable', 'array', 'max:10'],
            'slots.*' => ['date'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $r = $this->requests->offer($timeRequest, $p, $data['slots'] ?? [], $data['comment'] ?? null);

        return response()->json(['data' => $this->requests->toApi($r, $p->timezone, true)]);
    }

    public function close(Request $request, SessionTimeRequest $timeRequest): JsonResponse
    {
        $p = $this->psychologist($request);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $r = $this->requests->close($timeRequest, $p, $data['comment'] ?? null);

        return response()->json(['data' => $this->requests->toApi($r, $p->timezone, true)]);
    }

    private function psychologist(Request $request): Psychologist
    {
        $p = Psychologist::where('user_id', $request->user()->id)->first();
        abort_unless($p, 404, 'Профиль психолога не найден.');

        return $p;
    }
}

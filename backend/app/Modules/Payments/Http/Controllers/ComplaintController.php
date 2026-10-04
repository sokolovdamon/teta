<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Payments\Models\ChargeComplaint;
use App\Modules\Payments\Services\ComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CL-07: complaints about a charge — submit, see status and the 14-working-day deadline, answer, withdraw. */
class ComplaintController extends Controller
{
    public function __construct(private ComplaintService $complaints) {}

    public function index(Request $request): JsonResponse
    {
        $items = ChargeComplaint::with('session.psychologist')->where('client_id', $request->user()->id)->orderByDesc('created_at')->get();

        return response()->json(['data' => $items->map(fn ($c) => ComplaintService::toApi($c))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
        $session = TherapySession::findOrFail($data['session_id']);
        $c = $this->complaints->create($request->user(), $session, $data['reason']);

        return response()->json(['data' => ComplaintService::toApi($c->fresh())], 201);
    }

    public function answer(Request $request, ChargeComplaint $complaint): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:5000']]);
        $c = $this->complaints->answer($complaint, $request->user(), $data['text']);

        return response()->json(['data' => ComplaintService::toApi($c->fresh())]);
    }

    public function withdraw(Request $request, ChargeComplaint $complaint): JsonResponse
    {
        $c = $this->complaints->withdraw($complaint, $request->user());

        return response()->json(['data' => ComplaintService::toApi($c->fresh())]);
    }
}

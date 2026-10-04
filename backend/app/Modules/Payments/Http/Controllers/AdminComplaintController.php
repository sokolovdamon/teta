<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\ChargeComplaint;
use App\Modules\Payments\Services\ComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADM-07: queue of complaints about a charge with the 14-working-day SLA (DEC-23, ST-05). */
class AdminComplaintController extends Controller
{
    public function __construct(private ComplaintService $complaints) {}

    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'open');
        $items = ChargeComplaint::with('session.psychologist')
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ComplaintService::OPEN))
            ->when($status !== 'open' && $status !== 'all', fn ($q) => $q->whereIn('status', explode(',', $status)))
            ->orderBy('due_date')->orderBy('created_at')
            ->limit(200)->get();

        return response()->json(['data' => $items->map(fn ($c) => ComplaintService::toApi($c, true))->values()]);
    }

    public function show(ChargeComplaint $complaint): JsonResponse
    {
        return response()->json(['data' => ComplaintService::toApi($complaint->load('session.psychologist'), true)]);
    }

    public function take(Request $request, ChargeComplaint $complaint): JsonResponse
    {
        return $this->respond($this->complaints->take($complaint, $request->user()));
    }

    public function ask(Request $request, ChargeComplaint $complaint): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:5000']]);

        return $this->respond($this->complaints->ask($complaint, $request->user(), $data['question']));
    }

    public function resume(Request $request, ChargeComplaint $complaint): JsonResponse
    {
        return $this->respond($this->complaints->resume($complaint, $request->user()));
    }

    public function reject(Request $request, ChargeComplaint $complaint): JsonResponse
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:5000']]);

        return $this->respond($this->complaints->reject($complaint, $request->user(), $data['comment']));
    }

    public function approve(Request $request, ChargeComplaint $complaint): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'integer', 'min:0'],
            'comment' => ['required', 'string', 'max:5000'],
        ]);

        return $this->respond($this->complaints->approve($complaint, $request->user(), (int) ($data['amount'] ?? 0), $data['comment']));
    }

    private function respond(ChargeComplaint $c): JsonResponse
    {
        return response()->json(['data' => ComplaintService::toApi($c->fresh('session.psychologist'), true)]);
    }
}

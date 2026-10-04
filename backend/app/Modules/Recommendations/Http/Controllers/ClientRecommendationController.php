<?php

namespace App\Modules\Recommendations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Recommendations\Models\Recommendation;
use App\Modules\Recommendations\Services\RecommendationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CL-05: recommendations the client received. Drafts and revoked ones are never shown. */
class ClientRecommendationController extends Controller
{
    private const RELATIONS = ['files', 'session:id,starts_at', 'psychologist:id,slug,first_name,last_name'];

    public function __construct(private RecommendationService $service) {}

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['active', 'done', 'all'])]]);
        $status = $data['status'] ?? 'all';
        $clientId = $request->user()->id;

        $items = Recommendation::where('client_id', $clientId)
            ->whereIn('status', match ($status) {
                'active' => [Recommendation::SENT, Recommendation::VIEWED],
                'done' => [Recommendation::DONE],
                default => Recommendation::VISIBLE_TO_CLIENT,
            })
            ->with(self::RELATIONS)
            ->orderByRaw('case when status = ? then 0 when status = ? then 1 else 2 end', [Recommendation::SENT, Recommendation::VIEWED])
            ->orderByDesc('sent_at')
            ->paginate(30);

        return response()->json([
            'data' => $items->getCollection()->map(fn (Recommendation $r) => $r->toApi(forClient: true))->values(),
            'meta' => [
                'current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(), 'total' => $items->total(),
                'unread' => Recommendation::where('client_id', $clientId)->where('status', Recommendation::SENT)->count(),
            ],
        ]);
    }

    /** Opening marks a sent recommendation viewed (after that it can no longer be revoked). */
    public function show(Request $request, string $recommendation)
    {
        $reco = $this->service->markViewed($this->own($request, $recommendation), $request->user());

        return response()->json(['data' => $reco->load(self::RELATIONS)->toApi(forClient: true)]);
    }

    public function done(Request $request, string $recommendation)
    {
        $reco = $this->service->markDone($this->own($request, $recommendation), $request->user());

        return response()->json(['data' => $reco->load(self::RELATIONS)->toApi(forClient: true)]);
    }

    public function undo(Request $request, string $recommendation)
    {
        $reco = $this->service->markNotDone($this->own($request, $recommendation), $request->user());

        return response()->json(['data' => $reco->load(self::RELATIONS)->toApi(forClient: true)]);
    }

    private function own(Request $request, string $id): Recommendation
    {
        $reco = Recommendation::where('id', $id)
            ->where('client_id', $request->user()->id)
            ->whereIn('status', Recommendation::VISIBLE_TO_CLIENT)
            ->first();
        abort_unless($reco !== null, 404, 'Рекомендация не найдена или отозвана психологом.');

        return $reco;
    }
}

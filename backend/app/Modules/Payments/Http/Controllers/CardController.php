<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\CardBinding;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Cards (CL-07, WIZ-06, PRO-09): bind with the payer (3-D Secure), list, make default, remove (376-ФЗ). */
class CardController extends Controller
{
    public function __construct(private CardService $cards) {}

    public function index(Request $request): JsonResponse
    {
        $purpose = $request->query('purpose', 'payment');

        return response()->json(['data' => $this->cards->active($request->user()->id, (string) $purpose)->map->toApi()->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['nullable', Rule::in(['payment', 'payout'])],
            'return_path' => ['nullable', 'string', 'max:255', 'regex:#^/[^/]#'],
        ]);
        $purpose = $data['purpose'] ?? 'payment';
        if ($purpose === 'payout') {
            abort_unless($request->user()->hasRole('psychologist', 'supervisor'), 403);
        }
        $binding = $this->cards->startBinding($request->user(), $data['return_path'] ?? null, $purpose);

        return response()->json(['data' => $binding->toApi()], 201);
    }

    public function binding(Request $request, CardBinding $binding): JsonResponse
    {
        abort_unless($binding->user_id === $request->user()->id, 404);
        if ($binding->status === CardBinding::PENDING && $binding->updated_at < now()->subSeconds(20)) {
            $binding = $this->cards->resolve($binding);
        }

        return response()->json(['data' => $binding->fresh()->toApi()]);
    }

    public function makeDefault(Request $request, PaymentMethod $method): JsonResponse
    {
        abort_unless($method->user_id === $request->user()->id && $method->status === 'active', 404);
        $this->cards->makeDefault($method);

        return response()->json(['data' => $method->fresh()->toApi()]);
    }

    public function destroy(Request $request, PaymentMethod $method): JsonResponse
    {
        abort_unless($method->user_id === $request->user()->id && $method->status === 'active', 404);
        $result = $this->cards->remove($method);

        return response()->json(['ok' => true, ...$result]);
    }
}

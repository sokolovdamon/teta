<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Crm\Services\ClientDirectory;
use App\Modules\Crm\Services\ClientRelationship;
use App\Support\Events\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** PRO-05: the psychologist's clients and the client card. Everything is scoped to the current psychologist. */
class ClientController extends Controller
{
    public function __construct(private ClientDirectory $directory, private ClientRelationship $relationship) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(ClientDirectory::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $psychologist = ClientRelationship::psychologistOf($request->user());
        $page = $this->directory->paginate($psychologist, $data['search'] ?? null, $data['status'] ?? null);

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $client)
    {
        $psychologist = ClientRelationship::psychologistOf($request->user());
        $this->relationship->ensureClientOf($psychologist, $client);
        $card = $this->directory->find($psychologist, $client);
        abort_unless($card !== null, 404, 'Клиент не найден.');

        $access = $this->relationship->diaryAccess($psychologist, $client);
        $status = $card['status'];

        return response()->json(['data' => [
            ...$card,
            'requests' => $this->directory->requests($psychologist, $client),
            'diary' => $access ? ['available' => true, ...$access->toApi()] : ['available' => false, 'restricted' => false, 'until' => null],
            'can_finish' => ! in_array($status, ['finished', 'changed'], true) && $card['upcoming_count'] === 0,
            'can_recommend' => $status !== 'changed',
        ]]);
    }

    /** Sessions with this psychologist only (BR-RBAC-08): other specialists' sessions are never shown. */
    public function sessions(Request $request, string $client)
    {
        $psychologist = ClientRelationship::psychologistOf($request->user());
        $this->relationship->ensureClientOf($psychologist, $client);

        $sessions = $this->relationship->sessionsOfPair($psychologist->id, $client)
            ->orderByDesc('starts_at')
            ->paginate(50);

        return response()->json([
            'data' => $sessions->getCollection()->map(fn (TherapySession $s) => [
                'id' => $s->id,
                'starts_at' => $s->starts_at->toIso8601String(),
                'ends_at' => $s->ends_at->toIso8601String(),
                'duration_min' => $s->duration_min,
                'format' => $s->format,
                'status' => $s->status,
                'is_partner' => $s->partner_user_id === $client,
                'actual_duration_sec' => $s->actual_duration_sec,
            ])->values(),
            'meta' => ['current_page' => $sessions->currentPage(), 'last_page' => $sessions->lastPage(), 'per_page' => $sessions->perPage(), 'total' => $sessions->total()],
        ]);
    }

    /** «Работа завершена»: closes the diary window (DM-08) and starts the notes retention period. */
    public function finish(Request $request, string $client)
    {
        $psychologist = ClientRelationship::psychologistOf($request->user());
        $this->relationship->ensureClientOf($psychologist, $client);
        $card = $this->directory->find($psychologist, $client);
        abort_if(in_array($card['status'] ?? null, ['finished', 'changed'], true), 409, 'Работа с клиентом уже завершена.');
        abort_if(($card['upcoming_count'] ?? 0) > 0, 422, 'У клиента есть назначенные сессии. Завершить работу можно после них.');

        $at = now()->toImmutable();
        DB::transaction(function () use ($psychologist, $client, $at, $request) {
            $saved = $this->relationship->close($psychologist->id, $client, 'finished', $at);
            Outbox::record('crm.work.finished', $saved, [
                'psychologist_id' => $psychologist->id,
                'client_id' => $client,
                'at' => $at->toIso8601String(),
            ], $request->user()->id);
        });

        return response()->json(['data' => $this->directory->find($psychologist, $client)]);
    }
}

<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\PsychologistNote;
use App\Modules\Crm\Services\ClientRelationship;
use App\Modules\Psychologists\Models\Psychologist;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PRO-05: private notes. Only the author reads and edits them — every query is scoped by the author's
 * psychologist id; a note of another psychologist answers 404 exactly like a missing one. Nothing is audited.
 */
class NoteController extends Controller
{
    public function __construct(private ClientRelationship $relationship) {}

    public function index(Request $request, string $client)
    {
        $psychologist = ClientRelationship::psychologistOf($request->user());
        $this->relationship->ensureClientOf($psychologist, $client);

        $notes = PsychologistNote::where('psychologist_id', $psychologist->id)
            ->where('client_id', $client)
            ->with('session:id,starts_at')
            ->latest()->latest('id')
            ->paginate(50);

        return response()->json([
            'data' => $notes->getCollection()->map(fn (PsychologistNote $n) => $n->toApi())->values(),
            'meta' => ['current_page' => $notes->currentPage(), 'last_page' => $notes->lastPage(), 'per_page' => $notes->perPage(), 'total' => $notes->total()],
        ]);
    }

    public function store(Request $request, string $client)
    {
        $psychologist = ClientRelationship::psychologistOf($request->user());
        $this->relationship->ensureClientOf($psychologist, $client);
        $data = $this->validated($request, $psychologist, $client);

        $note = PsychologistNote::create([
            'psychologist_id' => $psychologist->id,
            'client_id' => $client,
            'session_id' => $data['session_id'] ?? null,
            'body' => $data['body'],
        ]);

        return response()->json(['data' => $note->load('session:id,starts_at')->toApi()], 201);
    }

    public function update(Request $request, string $client, string $note)
    {
        $psychologist = ClientRelationship::psychologistOf($request->user());
        $model = $this->own($psychologist, $client, $note);
        $data = $this->validated($request, $psychologist, $client, partial: true);
        $model->update($data);

        return response()->json(['data' => $model->load('session:id,starts_at')->toApi()]);
    }

    public function destroy(Request $request, string $client, string $note)
    {
        $psychologist = ClientRelationship::psychologistOf($request->user());
        $this->own($psychologist, $client, $note)->delete();

        return response()->json(['ok' => true]);
    }

    private function own(Psychologist $psychologist, string $client, string $note): PsychologistNote
    {
        $model = PsychologistNote::where('id', $note)->where('psychologist_id', $psychologist->id)->where('client_id', $client)->first();
        abort_unless($model !== null, 404, 'Заметка не найдена.');

        return $model;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Psychologist $psychologist, string $client, bool $partial = false): array
    {
        return $request->validate([
            'body' => [$partial ? 'sometimes' : 'required', 'string', 'min:1', 'max:10000'],
            'session_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('therapy_sessions', 'id')->where(
                fn ($q) => $q->where('psychologist_id', $psychologist->id)->where(fn ($w) => $w->where('client_id', $client)->orWhere('partner_user_id', $client)),
            )],
        ], [
            'body.required' => 'Напишите текст заметки.',
            'body.max' => 'Заметка слишком длинная: не больше 10 000 знаков.',
            'session_id.exists' => 'Сессия не найдена среди ваших сессий с этим клиентом.',
        ]);
    }
}

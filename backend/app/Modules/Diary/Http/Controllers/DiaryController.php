<?php

namespace App\Modules\Diary\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Diary\Models\DiaryEntry;
use App\Modules\Diary\Models\EmotionTag;
use App\Modules\Diary\Services\DiaryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CL-01, CL-06: the client's own diary. Every query is scoped to the authenticated client. */
class DiaryController extends Controller
{
    public function __construct(private DiaryService $diary) {}

    /** Whether to offer the check-in now (not more than once a day) and the tags for the form. */
    public function prompt(Request $request)
    {
        $client = $request->user();

        return response()->json(['data' => [
            'show' => $this->diary->shouldPrompt($client),
            'today' => $this->diary->today($client)->toDateString(),
            'tags' => EmotionTag::active()->ordered()->get()->map(fn (EmotionTag $t) => $t->toApi())->values(),
        ]]);
    }

    public function skip(Request $request)
    {
        $this->diary->skip($request->user());

        return response()->json(['ok' => true]);
    }

    public function tags()
    {
        return response()->json(['data' => EmotionTag::active()->ordered()->get()->map(fn (EmotionTag $t) => $t->toApi())->values()]);
    }

    public function index(Request $request)
    {
        $entries = DiaryEntry::where('client_id', $request->user()->id)
            ->orderByDesc('recorded_at')
            ->paginate(30);
        $tags = $this->diary->tagsById();

        return response()->json([
            'data' => $entries->getCollection()->map(fn (DiaryEntry $e) => $e->toClientApi($tags))->values(),
            'meta' => ['current_page' => $entries->currentPage(), 'last_page' => $entries->lastPage(), 'per_page' => $entries->perPage(), 'total' => $entries->total()],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'mood' => ['required', 'integer', 'between:'.DiaryEntry::MOOD_MIN.','.DiaryEntry::MOOD_MAX],
            'tag_ids' => ['nullable', 'array', 'max:'.DiaryEntry::TAGS_MAX],
            'tag_ids.*' => ['uuid', Rule::exists('emotion_tags', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:'.DiaryEntry::NOTE_MAX],
        ], [
            'mood.required' => 'Выберите, как вы себя чувствуете.',
            'mood.between' => 'Выберите настроение по шкале от 1 до 5.',
            'tag_ids.max' => 'Можно выбрать не больше '.DiaryEntry::TAGS_MAX.' меток.',
            'tag_ids.*.exists' => 'Такой метки нет в списке.',
            'note.max' => 'Заметка — не больше '.DiaryEntry::NOTE_MAX.' знаков.',
        ]);

        $entry = $this->diary->record($request->user(), (int) $data['mood'], $data['tag_ids'] ?? [], $data['note'] ?? null);

        return response()->json(['data' => $entry->toClientApi($this->diary->tagsById())], 201);
    }

    public function destroy(Request $request, string $entry)
    {
        $deleted = DiaryEntry::where('id', $entry)->where('client_id', $request->user()->id)->delete();
        abort_unless($deleted > 0, 404, 'Запись не найдена.');

        return response()->json(['ok' => true]);
    }

    public function dynamics(Request $request)
    {
        $data = self::periodInput($request);
        $client = $request->user();
        [$from, $to, $group] = $this->diary->period($client, $data['period'] ?? null, $data['from'] ?? null, $data['to'] ?? null, $data['group'] ?? null);

        return response()->json(['data' => $this->diary->dynamics($client->id, $from, $to, $group)]);
    }

    /** Period query of the dynamics endpoints: a preset or explicit dates, at most a year. */
    public static function periodInput(Request $request): array
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(array_keys(DiaryService::PERIODS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group' => ['nullable', Rule::in(DiaryService::GROUPS)],
        ]);
        if (isset($data['from']) && (strtotime($data['to'] ?? 'today') - strtotime($data['from'])) > 366 * 86400) {
            abort(422, 'Период — не больше года.');
        }

        return $data;
    }
}

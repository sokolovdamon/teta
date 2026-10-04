<?php

namespace App\Modules\Recommendations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Services\ClientRelationship;
use App\Modules\Recommendations\Models\Recommendation;
use App\Modules\Recommendations\Services\RecommendationService;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** PRO-07: recommendations of the psychologist. Only the author sees and changes them. */
class ProRecommendationController extends Controller
{
    public function __construct(private RecommendationService $service, private ClientRelationship $relationship) {}

    public function index(Request $request, string $client)
    {
        $psychologist = ClientRelationship::psychologistOf($request->user());
        $this->relationship->ensureClientOf($psychologist, $client);

        $items = Recommendation::where('psychologist_id', $psychologist->id)
            ->where('client_id', $client)
            ->with(['files', 'session:id,starts_at'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $items->map(fn (Recommendation $r) => $r->toApi())->values(),
            'sessions' => $this->service->eligibleSessions($psychologist, $client)->values(),
            'window_days' => Settings::int('P-RECO-WINDOW'),
        ]);
    }

    public function show(Request $request, string $recommendation)
    {
        $reco = $this->own($request, $recommendation);

        return response()->json(['data' => $reco->load(['files', 'session:id,starts_at'])->toApi()]);
    }

    public function store(Request $request)
    {
        $psychologist = ClientRelationship::psychologistOf($request->user());
        $data = $request->validate([
            'session_id' => ['required', 'uuid'],
            ...$this->rules(),
            'send' => ['sometimes', 'boolean'],
        ], $this->messages());

        $reco = DB::transaction(function () use ($psychologist, $request, $data) {
            $reco = $this->service->create($psychologist, $request->user(), $data);

            return $request->boolean('send') ? $this->service->send($reco, $request->user()) : $reco;
        });

        return response()->json(['data' => $reco->fresh()->load(['files', 'session:id,starts_at'])->toApi()], 201);
    }

    public function update(Request $request, string $recommendation)
    {
        $reco = $this->own($request, $recommendation);
        $data = $request->validate($this->rules(partial: true), $this->messages());
        $reco = $this->service->update($reco, $request->user(), $data);

        return response()->json(['data' => $reco->load(['files', 'session:id,starts_at'])->toApi()]);
    }

    public function send(Request $request, string $recommendation)
    {
        $reco = $this->service->send($this->own($request, $recommendation), $request->user());

        return response()->json(['data' => $reco->fresh()->load(['files', 'session:id,starts_at'])->toApi()]);
    }

    public function revoke(Request $request, string $recommendation)
    {
        $reco = $this->service->revoke($this->own($request, $recommendation), $request->user());

        return response()->json(['data' => $reco->fresh()->load(['files', 'session:id,starts_at'])->toApi()]);
    }

    public function destroy(Request $request, string $recommendation)
    {
        $reco = $this->own($request, $recommendation);
        abort_unless($reco->status === Recommendation::DRAFT, 409, 'Удалить можно только черновик.');
        $reco->delete();

        return response()->json(['ok' => true]);
    }

    private function own(Request $request, string $id): Recommendation
    {
        $psychologist = ClientRelationship::psychologistOf($request->user());
        $reco = Recommendation::where('id', $id)->where('psychologist_id', $psychologist->id)->first();
        abort_unless($reco !== null, 404, 'Рекомендация не найдена.');

        return $reco;
    }

    /** @return array<string, mixed> */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'type' => [$required, Rule::in(Recommendation::TYPES)],
            'title' => [$required, 'string', 'min:2', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'links' => ['sometimes', 'nullable', 'array', 'max:10'],
            // http(s) links or platform paths; protocol-relative "//host" is not a platform path.
            'links.*.url' => ['required', 'string', 'max:2000', 'regex:#^(https?://|/(?!/))\S*$#i'],
            'links.*.title' => ['nullable', 'string', 'max:200'],
            'links.*.kb_material_id' => ['nullable', 'uuid'],
            'file_ids' => ['sometimes', 'array', 'max:10'],
            'file_ids.*' => ['uuid'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'title.required' => 'Добавьте заголовок.',
            'type.required' => 'Выберите тип рекомендации.',
            'links.*.url.regex' => 'Ссылка должна начинаться с https:// или быть адресом страницы платформы.',
            'due_date.after_or_equal' => 'Срок не может быть в прошлом.',
            'file_ids.max' => 'Можно прикрепить не больше 10 файлов.',
        ];
    }
}

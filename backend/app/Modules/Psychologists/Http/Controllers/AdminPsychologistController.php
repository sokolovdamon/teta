<?php

namespace App\Modules\Psychologists\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Audit;
use App\Modules\Catalog\Http\PsychologistPresenter;
use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Psychologists\Http\PsychologistViews;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Psychologists\Models\QualificationDocument;
use App\Modules\Psychologists\Services\ProfileService;
use App\Modules\Psychologists\Services\QualificationService;
use App\Modules\Psychologists\Services\WorkStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ADM-03: qualification check, profile and video moderation, price categories, activity and work status.
 * Every decision is written to the audit journal (ADM-25).
 */
class AdminPsychologistController extends Controller
{
    public function __construct(private PsychologistViews $views)
    {
        PsychologistPresenter::flush();
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'qualification_status' => ['nullable', Rule::in(['draft', 'in_review', 'approved', 'rejected'])],
            'activity_status' => ['nullable', Rule::in(['grace', 'active_not_met', 'active_met', 'inactive'])],
            'work_status' => ['nullable', Rule::in(['active', 'paused', 'blocked'])],
            'price_category' => ['nullable', 'string', 'max:64'],
            'moderation' => ['nullable', Rule::in(['changes', 'video', 'documents', 'any'])],
            'published' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['submitted', 'created', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Psychologist::query()
            ->with(['user', 'photo'])
            ->withCount(['documents as documents_pending_count' => fn (Builder $q) => $q->where('status', 'pending')]);

        if (! empty($f['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($f['q'])).'%';
            $query->where(fn (Builder $q) => $q->whereRaw("lower(first_name || ' ' || last_name) LIKE ?", [$like])
                ->orWhereRaw('lower(slug) LIKE ?', [$like])
                ->orWhereHas('user', fn (Builder $u) => $u->whereRaw('lower(email) LIKE ?', [$like])));
        }
        foreach (['qualification_status', 'activity_status', 'work_status'] as $field) {
            if (! empty($f[$field])) {
                $query->where($field, $f[$field]);
            }
        }
        if (isset($f['published'])) {
            $query->where('is_published', (bool) $f['published']);
        }
        if (! empty($f['price_category'])) {
            $c = PriceCategory::where('code', $f['price_category'])->orWhere('id', $this->uuidOrNil($f['price_category']))->first();
            $c ? $query->where('price_individual', '>=', $c->min_price)->when($c->max_price !== null, fn ($q) => $q->where('price_individual', '<=', $c->max_price))
                : $query->whereRaw('1 = 0');
        }
        match ($f['moderation'] ?? null) {
            'changes' => $query->whereNotNull('pending_changes'),
            'video' => $query->where('video_status', 'pending'),
            'documents' => $query->whereHas('documents', fn (Builder $q) => $q->where('status', 'pending'))->where('qualification_status', 'approved'),
            'any' => $query->where(fn (Builder $q) => $q->whereNotNull('pending_changes')->orWhere('video_status', 'pending')
                ->orWhere('qualification_status', 'in_review')
                ->orWhere(fn (Builder $w) => $w->where('qualification_status', 'approved')->whereHas('documents', fn (Builder $d) => $d->where('status', 'pending')))),
            default => null,
        };
        match ($f['sort'] ?? 'submitted') {
            'name' => $query->orderBy('last_name')->orderBy('first_name'),
            'created' => $query->orderByDesc('created_at'),
            default => $query->orderByRaw("CASE WHEN qualification_status = 'in_review' THEN 0 ELSE 1 END")
                ->orderBy('qualification_submitted_at')->orderByDesc('created_at'),
        };

        $page = $query->paginate((int) ($f['per_page'] ?? 30));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Psychologist $p) => $this->views->adminRow($p))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'counts' => [
                'in_review' => Psychologist::where('qualification_status', 'in_review')->count(),
                'changes' => Psychologist::whereNotNull('pending_changes')->count(),
                'video' => Psychologist::where('video_status', 'pending')->count(),
            ],
        ]);
    }

    public function show(Request $request, Psychologist $psychologist): JsonResponse
    {
        $card = $this->views->adminCard($psychologist);
        if ($card['documents'] !== []) {
            // SEQ-12: every view of private qualification documents is audited.
            Audit::log('ADM-03', 'psychologist.documents_viewed', $psychologist, ['documents' => count($card['documents'])]);
        }

        return response()->json(['data' => $card]);
    }

    public function approveQualification(Request $request, Psychologist $psychologist, QualificationService $qualification): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $qualification->approve($psychologist, $request->user(), $data['comment'] ?? null);

        return $this->card($psychologist);
    }

    public function rejectQualification(Request $request, Psychologist $psychologist, QualificationService $qualification): JsonResponse
    {
        $data = $request->validate([
            'comment' => ['required', 'string', 'min:3', 'max:2000'],
            'document_ids' => ['sometimes', 'array'],
            'document_ids.*' => ['uuid'],
        ], ['comment.required' => 'Комментарий обязателен: психолог увидит причину отклонения.']);
        $qualification->reject($psychologist, $request->user(), $data['comment'], $data['document_ids'] ?? []);

        return $this->card($psychologist);
    }

    public function reviewDocument(Request $request, Psychologist $psychologist, QualificationDocument $document, QualificationService $qualification): JsonResponse
    {
        abort_unless($document->psychologist_id === $psychologist->id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'comment' => ['nullable', 'required_if:status,rejected', 'string', 'max:2000'],
        ], ['comment.required_if' => 'Укажите причину отклонения документа.']);
        $qualification->reviewDocument($psychologist, $document, $data['status'], $data['comment'] ?? null, $request->user());

        return $this->card($psychologist);
    }

    public function approveChanges(Request $request, Psychologist $psychologist, ProfileService $profile): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $profile->approvePending($psychologist, $request->user(), $data['comment'] ?? null);

        return $this->card($psychologist);
    }

    public function rejectChanges(Request $request, Psychologist $psychologist, ProfileService $profile): JsonResponse
    {
        $data = $request->validate(['comment' => ['required', 'string', 'min:3', 'max:2000']], ['comment.required' => 'Комментарий обязателен.']);
        $profile->rejectPending($psychologist, $request->user(), $data['comment']);

        return $this->card($psychologist);
    }

    public function approveVideo(Request $request, Psychologist $psychologist, ProfileService $profile): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $profile->approveVideo($psychologist, $request->user(), $data['comment'] ?? null);

        return $this->card($psychologist);
    }

    public function rejectVideo(Request $request, Psychologist $psychologist, ProfileService $profile): JsonResponse
    {
        $data = $request->validate(['comment' => ['required', 'string', 'min:3', 'max:2000']], ['comment.required' => 'Комментарий обязателен.']);
        $profile->rejectVideo($psychologist, $request->user(), $data['comment']);

        return $this->card($psychologist);
    }

    public function block(Request $request, Psychologist $psychologist, WorkStatusService $status): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']], ['reason.required' => 'Укажите причину блокировки.']);
        $status->block($psychologist, $request->user(), $data['reason']);

        return $this->card($psychologist);
    }

    public function unblock(Request $request, Psychologist $psychologist, WorkStatusService $status): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $status->unblock($psychologist, $request->user(), $data['comment'] ?? null);

        return $this->card($psychologist);
    }

    private function card(Psychologist $p): JsonResponse
    {
        return response()->json(['data' => $this->views->adminCard($p->fresh())]);
    }

    private function uuidOrNil(string $value): string
    {
        return Str::isUuid($value) ? $value : '00000000-0000-0000-0000-000000000000';
    }
}

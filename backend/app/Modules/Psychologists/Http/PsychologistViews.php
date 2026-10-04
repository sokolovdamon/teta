<?php

namespace App\Modules\Psychologists\Http;

use App\Modules\Catalog\Http\PsychologistPresenter;
use App\Modules\Catalog\Services\NearestSlots;
use App\Modules\Files\Models\StoredFile;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Psychologists\Models\QualificationDocument;
use App\Modules\Psychologists\Services\ActivityService;
use App\Modules\Psychologists\Services\ProfileCompleteness;
use App\Modules\Psychologists\Services\ProfileService;
use App\Modules\Psychologists\Services\QualificationService;
use App\Support\Settings\Settings;
use App\Support\StateMachine\StateTransition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** JSON views of a psychologist for the cabinet (PRO-01, PRO-02) and the admin panel (ADM-03). */
class PsychologistViews
{
    public function __construct(
        private ProfileService $profile,
        private ProfileCompleteness $completeness,
        private QualificationService $qualification,
        private ActivityService $activity,
        private NearestSlots $nearest,
    ) {}

    /** PRO-02: the form shows published values with pending ones on top (BR-PSY-05). */
    public function own(Psychologist $p): array
    {
        $p->load(['photo', 'video', 'approvedVideo', 'approaches', 'specializations', 'requests']);
        $values = $this->profile->effectiveValues($p);
        $pending = $this->profile->ordered((array) ($p->pending_changes ?? []));
        $photoId = $values['photo_file_id'] ?? null;

        return [
            ...$this->statuses($p),
            'requires_moderation' => $this->profile->requiresModeration($p),
            'values' => $values,
            'photo_url' => $photoId ? StoredFile::find($photoId)?->url() : null,
            'published_photo_url' => $p->photo?->url(),
            'pending' => $pending ? [
                'fields' => array_keys($pending),
                'labels' => array_map(fn ($f) => ProfileCompleteness::LABELS[$f] ?? $f, array_keys($pending)),
                'submitted_at' => $p->pending_submitted_at?->toIso8601ZuluString(),
            ] : null,
            'last_review' => $p->pending_reviewed_at ? [
                'comment' => $p->pending_review_comment,
                'reviewed_at' => $p->pending_reviewed_at->toIso8601ZuluString(),
            ] : null,
            'formats' => ['works_individual' => (bool) $p->works_individual, 'works_pair' => (bool) $p->works_pair],
            'prices' => $this->prices($p),
            'price_history' => $this->priceHistory($p, 10),
            'video' => $this->video($p),
            'video_limits' => (array) Settings::get('P-VIDEO-CARD-LIMITS'),
            'missing' => $this->completeness->missingProfile($p),
            'timezone' => $p->timezone,
        ];
    }

    /** PRO-01 */
    public function qualification(Psychologist $p): array
    {
        $p->load(['documents.file']);

        return [
            'status' => $p->qualification_status,
            'comment' => $p->qualification_comment,
            'submitted_at' => $p->qualification_submitted_at?->toIso8601ZuluString(),
            'qualified_at' => $p->qualified_at?->toIso8601ZuluString(),
            'documents' => $p->documents->sortBy('created_at')->values()
                ->map(fn (QualificationDocument $d) => $this->qualification->documentRow($d, true) + [
                    'can_delete' => match ($p->qualification_status) {
                        'draft', 'rejected' => true,
                        'approved' => $d->status !== 'approved',
                        default => false,
                    },
                ])->all(),
            'history' => $this->qualification->history($p),
            'missing' => $this->completeness->missingForSubmission($p),
            'can_submit' => in_array($p->qualification_status, ['draft', 'rejected'], true),
            'can_upload' => $p->qualification_status !== 'in_review',
            'document_kinds' => Psychologist::DOCUMENT_KINDS,
            'education_kinds' => Psychologist::EDUCATION_DOCUMENT_KINDS,
        ];
    }

    /** ADM-03 list row. */
    public function adminRow(Psychologist $p): array
    {
        $category = PsychologistPresenter::category($p->price_individual);

        return [
            ...$this->statuses($p),
            'email' => $p->user?->email,
            'photo_url' => $p->photo?->url(),
            'price_individual' => $p->price_individual,
            'price_pair' => $p->price_pair,
            'price_category' => $category ? ['code' => $category->code, 'title' => $category->title] : null,
            'qualification_submitted_at' => $p->qualification_submitted_at?->toIso8601ZuluString(),
            'qualified_at' => $p->qualified_at?->toIso8601ZuluString(),
            'has_pending_changes' => ! empty($p->pending_changes),
            'video_status' => $p->video_status,
            'documents_pending' => (int) ($p->getAttributes()['documents_pending_count']
                ?? ($p->relationLoaded('documents') ? $p->documents->where('status', 'pending')->count() : 0)),
            'created_at' => $p->created_at?->toIso8601ZuluString(),
        ];
    }

    /** ADM-03 card. Documents come with short-lived signed links (X-09); opening the card is audited (SEQ-12). */
    public function adminCard(Psychologist $p): array
    {
        $p->load(['user', 'photo', 'video', 'approvedVideo', 'approaches', 'specializations', 'requests', 'documents.file', 'scheduleIntervals']);
        $format = $p->works_individual && $p->price_individual ? 'individual' : 'pair';

        return [
            ...$this->adminRow($p),
            'user' => $p->user ? [
                'id' => $p->user->id, 'email' => $p->user->email, 'status' => $p->user->status,
                'email_verified' => $p->user->email_verified_at !== null, 'created_at' => $p->user->created_at?->toIso8601ZuluString(),
            ] : null,
            'profile' => [
                ...$this->profile->currentValues($p),
                'approaches' => $p->approaches->sortBy('sort')->values()->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'explanation' => $a->pivot->explanation])->all(),
                'specializations' => $p->specializations->sortBy('sort')->pluck('title')->values()->all(),
                'requests' => $p->requests->sortBy('carousel_sort')->pluck('title')->values()->all(),
            ],
            'formats' => ['works_individual' => (bool) $p->works_individual, 'works_pair' => (bool) $p->works_pair],
            'prices' => $this->prices($p),
            'price_history' => $this->priceHistory($p, 20),
            'qualification' => [
                'status' => $p->qualification_status,
                'comment' => $p->qualification_comment,
                'submitted_at' => $p->qualification_submitted_at?->toIso8601ZuluString(),
                'qualified_at' => $p->qualified_at?->toIso8601ZuluString(),
                'history' => $this->qualification->history($p, true),
            ],
            'documents' => $p->documents->sortBy('created_at')->values()->map(fn ($d) => $this->qualification->documentRow($d, true))->all(),
            'pending' => ! empty($p->pending_changes) ? [
                'submitted_at' => $p->pending_submitted_at?->toIso8601ZuluString(),
                'diff' => $this->profile->pendingDiff($p),
            ] : null,
            'last_review' => $p->pending_reviewed_at ? ['comment' => $p->pending_review_comment, 'reviewed_at' => $p->pending_reviewed_at->toIso8601ZuluString()] : null,
            'video' => $this->video($p),
            'work_status_history' => $this->transitions($p, 'work_status'),
            'activity' => $this->activity->currentStatus($p),
            'schedule' => [
                'intervals' => $p->scheduleIntervals->count(),
                'nearest_slot' => $this->nearest->get($p, $format)?->toIso8601ZuluString(),
            ],
            'missing' => $this->completeness->missingProfile($p),
            'views_count' => (int) $p->views_count,
        ];
    }

    private function statuses(Psychologist $p): array
    {
        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->fullName(),
            'public_path' => "/psychologists/{$p->slug}",
            'qualification_status' => $p->qualification_status,
            'activity_status' => $p->activity_status,
            'work_status' => $p->work_status,
            'work_status_reason' => $p->work_status_reason,
            'is_published' => (bool) $p->is_published,
            'is_bookable' => $p->isBookable(),
        ];
    }

    private function prices(Psychologist $p): array
    {
        $category = PsychologistPresenter::category($p->price_individual);

        return [
            'price_individual' => $p->price_individual,
            'price_pair' => $p->price_pair,
            'price_category' => $category ? ['code' => $category->code, 'title' => $category->title] : null,
        ];
    }

    private function priceHistory(Psychologist $p, int $limit): array
    {
        return DB::table('psychologist_price_history')
            ->where('psychologist_id', $p->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'price_individual' => $r->price_individual === null ? null : (int) $r->price_individual,
                'price_pair' => $r->price_pair === null ? null : (int) $r->price_pair,
                'created_at' => CarbonImmutable::parse($r->created_at, 'UTC')->toIso8601ZuluString(),
            ])->all();
    }

    private function video(Psychologist $p): array
    {
        return [
            'status' => $p->video_status,
            'url' => $p->video?->url(),
            'approved_url' => $p->approvedVideo?->url(),
            'comment' => $p->video_comment,
            'duration_sec' => $p->video_duration_sec,
            'submitted_at' => $p->video_submitted_at?->toIso8601ZuluString(),
            'reviewed_at' => $p->video_reviewed_at?->toIso8601ZuluString(),
        ];
    }

    private function transitions(Psychologist $p, string $field): array
    {
        return StateTransition::query()
            ->where('model_type', $p->getMorphClass())->where('model_id', $p->id)->where('field', $field)
            ->with('actor')->orderByDesc('created_at')->limit(20)->get()
            ->map(fn (StateTransition $t) => [
                'from' => $t->from, 'to' => $t->to, 'reason' => $t->reason,
                'actor' => $t->actor ? trim($t->actor->name.' '.($t->actor->last_name ?? '')) : null,
                'at' => $t->created_at?->toIso8601ZuluString(),
            ])->all();
    }
}

<?php

namespace App\Modules\Psychologists\Models;

use App\Models\User;
use App\Modules\Dictionaries\Models\Approach;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Dictionaries\Models\Specialization;
use App\Modules\Files\Models\StoredFile;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\ScheduleInterval;
use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * PSY. Statuses:
 *  - qualification_status (ST-08): draft → in_review → approved | rejected; rejected → in_review; approved → rejected;
 *  - activity_status (ST-09): grace → active_not_met ⇄ active_met; active_not_met → inactive → active_met;
 *  - work_status (BR-PSY-06): active | paused | blocked.
 * A profile is visible in the catalog and matching only when it is bookable(): approved, published, active work status,
 * and activity status not "inactive" (DEC-21, DEC-37).
 */
class Psychologist extends Model
{
    use HasStateMachine, HasUuids, SoftDeletes, UtcDates;

    protected static string $eventPrefix = 'psy';

    protected $guarded = ['id', 'qualification_status', 'activity_status', 'work_status', 'video_status', 'search_vector'];

    protected $hidden = ['search_vector'];

    /** Document kinds that confirm psychological education (BR-PSY-02): a diploma or professional retraining. */
    public const EDUCATION_DOCUMENT_KINDS = ['diploma', 'retraining'];

    public const DOCUMENT_KINDS = ['diploma', 'retraining', 'certificate', 'other'];

    protected function casts(): array
    {
        return [
            'education' => 'array',
            'pending_changes' => 'array',
            'birth_year' => 'integer',
            'experience_years' => 'integer',
            'price_individual' => 'integer',
            'price_pair' => 'integer',
            'works_individual' => 'boolean',
            'works_pair' => 'boolean',
            'is_published' => 'boolean',
            'qualification_submitted_at' => 'datetime',
            'qualified_at' => 'datetime',
            'published_at' => 'datetime',
            'pending_submitted_at' => 'datetime',
            'pending_reviewed_at' => 'datetime',
            'video_submitted_at' => 'datetime',
            'video_reviewed_at' => 'datetime',
        ];
    }

    protected static function transitions(): array
    {
        return [
            'qualification_status' => [
                'draft' => ['in_review'],
                'in_review' => ['approved', 'rejected'],
                'rejected' => ['in_review'],
                'approved' => ['rejected'],
            ],
            'activity_status' => [
                '' => ['grace'],
                'grace' => ['active_not_met', 'active_met'],
                'active_not_met' => ['active_met', 'inactive'],
                'active_met' => ['active_not_met'],
                'inactive' => ['active_met'],
            ],
            'work_status' => [
                'active' => ['paused', 'blocked'],
                'paused' => ['active', 'blocked'],
                'blocked' => ['active'],
            ],
            // DEC-44: the video card is moderated separately and never affects qualification.
            'video_status' => [
                'none' => ['pending'],
                'pending' => ['pending', 'approved', 'rejected', 'none'],
                'approved' => ['pending', 'none'],
                'rejected' => ['pending', 'none'],
            ],
        ];
    }

    public function canTransition(string $to, string $field = 'status'): bool
    {
        $from = (string) $this->getAttribute($field);
        $map = static::transitions()[$field] ?? [];

        return in_array($to, $map[$from] ?? [], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'photo_file_id');
    }

    /** The latest uploaded video card (status in video_status). */
    public function video(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'video_file_id');
    }

    /** The video card shown on the site: the last approved one. */
    public function approvedVideo(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'video_approved_file_id');
    }

    public function priceCategory(): BelongsTo
    {
        return $this->belongsTo(PriceCategory::class);
    }

    public function approaches(): BelongsToMany
    {
        return $this->belongsToMany(Approach::class)->withPivot('explanation');
    }

    public function specializations(): BelongsToMany
    {
        return $this->belongsToMany(Specialization::class);
    }

    public function requests(): BelongsToMany
    {
        return $this->belongsToMany(ClientRequest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(QualificationDocument::class);
    }

    public function scheduleIntervals(): HasMany
    {
        return $this->hasMany(ScheduleInterval::class);
    }

    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function age(): ?int
    {
        return $this->birth_year ? (int) now()->year - (int) $this->birth_year : null;
    }

    /** Was the qualification ever confirmed (the public page exists, active or inactive)? */
    public function wasEverApproved(): bool
    {
        return $this->qualification_status === 'approved' || $this->qualified_at !== null;
    }

    /** New bookings are open for everyone only when this is true. */
    public function isBookable(): bool
    {
        return $this->qualification_status === 'approved'
            && $this->is_published
            && $this->work_status === 'active'
            && $this->activity_status !== 'inactive';
    }

    /** Profiles shown in the catalog, matching and search. */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('qualification_status', 'approved')
            ->where('is_published', true)
            ->where('work_status', 'active')
            ->where(fn ($q) => $q->whereNull('activity_status')->orWhere('activity_status', '!=', 'inactive'));
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug(Str::ascii($name)) ?: 'psychologist';
        $slug = $base;
        $i = 2;
        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function priceFor(string $format): ?int
    {
        return $format === 'pair' ? $this->price_pair : $this->price_individual;
    }
}

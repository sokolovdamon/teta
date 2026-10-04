<?php

namespace App\Modules\Booking\Models;

use App\Models\User;
use App\Modules\Corporate\Models\CorporateParticipation;
use App\Modules\Payments\Models\ChargeTask;
use App\Modules\Promo\Models\PromoCode;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * BOOK, ST-01. A reschedule is an event in history, not a status (BR-BOOK-07).
 * Events: book.session.{status}.
 */
class TherapySession extends Model
{
    use HasStateMachine, HasUuids, UtcDates;

    public const BOOKED = 'booked';

    public const PAID = 'paid';

    public const IN_PROGRESS = 'in_progress';

    public const HELD = 'held';

    public const CLIENT_NO_SHOW = 'client_no_show';

    public const PSY_NO_SHOW = 'psy_no_show';

    public const TECH_ISSUE = 'tech_issue';

    public const CANCELLED_BY_CLIENT = 'cancelled_by_client';

    public const CANCELLED_BY_PSY = 'cancelled_by_psy';

    public const CANCELLED_BY_SYSTEM = 'cancelled_by_system';

    /** Statuses in which the slot is occupied. */
    public const ACTIVE_STATUSES = [self::BOOKED, self::PAID, self::IN_PROGRESS];

    public const FINAL_STATUSES = [
        self::HELD, self::CLIENT_NO_SHOW, self::PSY_NO_SHOW, self::TECH_ISSUE,
        self::CANCELLED_BY_CLIENT, self::CANCELLED_BY_PSY, self::CANCELLED_BY_SYSTEM,
    ];

    protected static string $eventPrefix = 'book.session';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'paid_at' => 'datetime',
            'charge_due_at' => 'datetime',
            'charge_deadline_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'outcome_at' => 'datetime',
            'client_request_ids' => 'array',
            'params' => 'array',
        ];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            self::BOOKED => [self::BOOKED, self::PAID, self::CANCELLED_BY_CLIENT, self::CANCELLED_BY_PSY, self::CANCELLED_BY_SYSTEM],
            self::PAID => [self::PAID, self::IN_PROGRESS, self::CANCELLED_BY_CLIENT, self::CANCELLED_BY_PSY, self::CANCELLED_BY_SYSTEM],
            self::IN_PROGRESS => [self::HELD, self::CLIENT_NO_SHOW, self::PSY_NO_SHOW, self::TECH_ISSUE],
            // Admin corrects the final outcome by the session log (ADM-04, BR-BOOK-13).
            self::HELD => [self::CLIENT_NO_SHOW, self::PSY_NO_SHOW, self::TECH_ISSUE],
            self::CLIENT_NO_SHOW => [self::HELD, self::PSY_NO_SHOW, self::TECH_ISSUE],
            self::PSY_NO_SHOW => [self::HELD, self::CLIENT_NO_SHOW, self::TECH_ISSUE],
            self::TECH_ISSUE => [self::HELD, self::CLIENT_NO_SHOW, self::PSY_NO_SHOW],
            self::CANCELLED_BY_CLIENT => [],
            self::CANCELLED_BY_PSY => [],
            self::CANCELLED_BY_SYSTEM => [],
        ]];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id')->withTrashed();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_id')->withTrashed();
    }

    public function psychologist(): BelongsTo
    {
        return $this->belongsTo(Psychologist::class)->withTrashed();
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function corporateParticipation(): BelongsTo
    {
        return $this->belongsTo(CorporateParticipation::class);
    }

    public function chargeTask(): HasOne
    {
        return $this->hasOne(ChargeTask::class);
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function scopeOccupying(Builder $q): Builder
    {
        return $q->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isCorporate(): bool
    {
        return $this->corporate_participation_id !== null;
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /** User may take part: the client, the pair partner or the psychologist. */
    public function hasParticipant(User $user): bool
    {
        return $user->id === $this->client_id || $user->id === $this->partner_user_id || $user->id === $this->psychologist?->user_id;
    }
}

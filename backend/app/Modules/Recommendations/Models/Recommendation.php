<?php

namespace App\Modules\Recommendations\Models;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Files\Models\StoredFile;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * RECO (PRO-07, CL-05). Statuses by DM-12: «Черновик» draft → «Отправлена» sent → «Просмотрена» viewed →
 * «Выполнена» done; «Отозвана» revoked — only before the client viewed it. The client may take back the done mark.
 * Events: reco.recommendation.{status}.
 */
class Recommendation extends Model
{
    use HasStateMachine, HasUuids, UtcDates;

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const VIEWED = 'viewed';

    public const DONE = 'done';

    public const REVOKED = 'revoked';

    /** Statuses the client sees. */
    public const VISIBLE_TO_CLIENT = [self::SENT, self::VIEWED, self::DONE];

    public const TYPES = ['task', 'exercise', 'material'];

    protected static string $eventPrefix = 'reco.recommendation';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return [
            'links' => 'array',
            'due_date' => 'date:Y-m-d',
            'window_until' => 'datetime',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'done_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            self::DRAFT => [self::SENT],
            self::SENT => [self::VIEWED, self::REVOKED],
            self::VIEWED => [self::DONE],
            self::DONE => [self::VIEWED],
            self::REVOKED => [],
        ]];
    }

    public function psychologist(): BelongsTo
    {
        return $this->belongsTo(Psychologist::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id')->withTrashed();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'session_id');
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(StoredFile::class, 'recommendation_files', 'recommendation_id', 'stored_file_id')
            ->withPivot('sort')->orderByPivot('sort');
    }

    /** @return array<string, mixed> */
    public function toApi(bool $forClient = false): array
    {
        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'links' => array_values($this->links ?? []),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'session_id' => $this->session_id,
            'session_starts_at' => $this->relationLoaded('session') ? $this->session?->starts_at?->toIso8601String() : null,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'viewed_at' => $this->viewed_at?->toIso8601String(),
            'done_at' => $this->done_at?->toIso8601String(),
            'files' => $this->relationLoaded('files') ? $this->files->map(fn (StoredFile $f) => $f->toApi())->values()->all() : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($forClient) {
            $psychologist = $this->relationLoaded('psychologist') ? $this->psychologist : null;
            $data['psychologist'] = $psychologist ? ['id' => $psychologist->id, 'slug' => $psychologist->slug, 'name' => $psychologist->fullName()] : null;
        } else {
            $data['client_id'] = $this->client_id;
            $data['revoked_at'] = $this->revoked_at?->toIso8601String();
            $data['window_until'] = $this->window_until->toIso8601String();
            $data['can_revoke'] = $this->status === self::SENT;
            $data['editable'] = $this->status === self::DRAFT;
        }

        return $data;
    }
}

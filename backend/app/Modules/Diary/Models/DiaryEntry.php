<?php

namespace App\Modules\Diary\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Diary entry (DEC-41): mood on the 5-emoji scale, optional emotion tags and an optional note.
 * "Сведения о состоянии": never sent to letters, logs, analytics or the audit journal.
 * The note is encrypted at rest and shown only to the client (BR-DIARY-03).
 */
class DiaryEntry extends Model
{
    use HasUuids, UtcDates;

    public const MOOD_MIN = 1;

    public const MOOD_MAX = 5;

    public const NOTE_MAX = 500;

    public const TAGS_MAX = 10;

    protected $guarded = ['id'];

    protected $hidden = ['note'];

    protected function casts(): array
    {
        return [
            'mood' => 'integer',
            'tag_ids' => 'array',
            'note' => 'encrypted',
            'recorded_at' => 'datetime',
            'local_date' => 'date:Y-m-d',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id')->withTrashed();
    }

    /**
     * Client's own view, with the note.
     *
     * @param  array<string, array{id: string, code: string, title: string}>  $tags  active and inactive tags by id
     * @return array<string, mixed>
     */
    public function toClientApi(array $tags): array
    {
        return [
            'id' => $this->id,
            'mood' => $this->mood,
            'tags' => collect($this->tag_ids ?? [])->map(fn ($id) => $tags[$id] ?? null)->filter()->values()->all(),
            'note' => $this->note,
            'recorded_at' => $this->recorded_at->toIso8601String(),
            'local_date' => $this->local_date->toDateString(),
        ];
    }
}

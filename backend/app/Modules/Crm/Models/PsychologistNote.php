<?php

namespace App\Modules\Crm\Models;

use App\Modules\Booking\Models\TherapySession;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Private note of a psychologist about a client (PRO-05). Visible only to the author: every query is scoped by
 * the author's psychologist id in code, there is no admin endpoint and no RBAC permission (TZ v2, section 8).
 * The body is encrypted at rest; it is never written to logs or the audit journal (BR-RBAC-07).
 */
class PsychologistNote extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['body'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }

    public function psychologist(): BelongsTo
    {
        return $this->belongsTo(Psychologist::class)->withTrashed();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'session_id');
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'session_id' => $this->session_id,
            'session_starts_at' => $this->relationLoaded('session') ? $this->session?->starts_at?->toIso8601String() : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

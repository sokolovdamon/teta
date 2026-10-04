<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM, PRO-05: marks on the pair "psychologist — client". The list of clients is computed from therapy_sessions;
 * a card row exists only when the work was finished or the client changed psychologist (DM-08).
 */
class ClientCard extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'work_finished_at' => 'datetime',
            'changed_psychologist_at' => 'datetime',
            'access_until' => 'datetime',
            'notes_purged_at' => 'datetime',
        ];
    }

    public function psychologist(): BelongsTo
    {
        return $this->belongsTo(Psychologist::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id')->withTrashed();
    }

    public static function for(string $psychologistId, string $clientId): self
    {
        return self::firstOrCreate(['psychologist_id' => $psychologistId, 'client_id' => $clientId]);
    }
}

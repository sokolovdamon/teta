<?php

namespace App\Modules\Booking\Models;

use App\Models\User;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Нет подходящего времени" (DEC-28): structured request instead of messaging the psychologist. */
class SessionTimeRequest extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['preferred' => 'array', 'offered_slots' => 'array', 'answered_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function psychologist(): BelongsTo
    {
        return $this->belongsTo(Psychologist::class);
    }
}

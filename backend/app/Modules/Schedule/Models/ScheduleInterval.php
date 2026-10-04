<?php

namespace App\Modules\Schedule\Models;

use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Weekly working interval in the psychologist's timezone (weekday: ISO 1 = Monday). */
class ScheduleInterval extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    public function psychologist(): BelongsTo
    {
        return $this->belongsTo(Psychologist::class);
    }
}

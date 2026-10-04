<?php

namespace App\Modules\Booking\Models;

use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** BR-CANC-08: a psychologist's cancel later than P-CHARGE-OFFSET before the start, or a no-show. No money is withheld. */
class QualityIncident extends Model
{
    use HasUuids, UtcDates;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function psychologist(): BelongsTo
    {
        return $this->belongsTo(Psychologist::class)->withTrashed();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'therapy_session_id');
    }
}

<?php

namespace App\Modules\Booking\Models;

use App\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A late booking (less than P-CHARGE-OFFSET before the start) that is being paid right now (SEQ-01, BR-BOOK-04).
 * The session does not exist until the payment succeeds; the slot stays held by a SlotHold.
 * pending → completed (session created as paid) | failed (payment declined or slot lost) | expired.
 */
class BookingIntent extends Model
{
    use HasUuids, UtcDates;

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'expires_at' => 'datetime', 'data' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function psychologist(): BelongsTo
    {
        return $this->belongsTo(Psychologist::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'therapy_session_id');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'session_id' => $this->therapy_session_id,
            'payment_id' => $this->payment_id,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'amount_due' => $this->amount_due,
            'balance_part' => $this->balance_part,
            'failure_reason' => $this->failure_reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}

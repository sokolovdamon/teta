<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ST-05: complaint about a charge, decided within P-COMPLAINT-REVIEW working days (DEC-23). */
class ChargeComplaint extends Model
{
    use HasStateMachine, HasUuids;

    protected static string $eventPrefix = 'pay.complaint';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'decided_at' => 'datetime'];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            'submitted' => ['in_review', 'withdrawn'],
            'in_review' => ['waiting_client', 'rejected', 'approved', 'withdrawn'],
            'waiting_client' => ['in_review'],
            'approved' => ['refunded'],
            'rejected' => [],
            'refunded' => [],
            'withdrawn' => [],
        ]];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'therapy_session_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

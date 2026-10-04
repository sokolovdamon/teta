<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ST-05: complaint about a charge, decided within P-COMPLAINT-REVIEW working days (DEC-23). */
class ChargeComplaint extends Model
{
    use HasStateMachine, HasUuids, UtcDates;

    protected static string $eventPrefix = 'pay.complaint';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'decided_at' => 'datetime', 'messages' => 'array', 'share_percent' => 'float', 'sla_alerted_at' => 'datetime', 'taken_at' => 'datetime', 'withdrawn_at' => 'datetime'];
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

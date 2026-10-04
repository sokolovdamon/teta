<?php

namespace App\Modules\Payments\Models;

use App\Modules\Booking\Models\TherapySession;
use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ST-02: the source of truth for an autocharge; queue jobs only trigger its processing. */
class ChargeTask extends Model
{
    use HasStateMachine, HasUuids, UtcDates;

    protected static string $eventPrefix = 'pay.charge';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'deadline_at' => 'datetime', 'next_attempt_at' => 'datetime', 'locked_at' => 'datetime', 'last_attempt_at' => 'datetime'];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            'scheduled' => ['scheduled', 'in_progress', 'cancelled'],
            'in_progress' => ['in_progress', 'succeeded', 'retry_wait', 'failed_final'],
            'retry_wait' => ['in_progress', 'succeeded', 'cancelled', 'failed_final'],
            'succeeded' => [],
            'failed_final' => [],
            'cancelled' => [],
        ]];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'therapy_session_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function balanceOperation(): BelongsTo
    {
        return $this->belongsTo(ClientBalanceOperation::class, 'balance_operation_id');
    }

    public function payerPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payer_payment_id');
    }

    public function attemptsLog(): HasMany
    {
        return $this->hasMany(ChargeAttempt::class)->orderBy('number');
    }
}

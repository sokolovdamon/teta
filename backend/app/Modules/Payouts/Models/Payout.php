<?php

namespace App\Modules\Payouts\Models;

use App\Models\User;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ST-07: weekly payout to one psychologist or supervisor. */
class Payout extends Model
{
    use HasStateMachine, HasUuids;

    protected static string $eventPrefix = 'payout';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            'checking' => ['blocked_supervision', 'deferred', 'in_registry'],
            'in_registry' => ['excluded', 'sent'],
            'sent' => ['paid', 'rejected', 'unknown'],
            'unknown' => ['paid', 'rejected'],
            'blocked_supervision' => [],
            'deferred' => [],
            'excluded' => [],
            'paid' => [],
            'rejected' => [],
        ]];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registry(): BelongsTo
    {
        return $this->belongsTo(PayoutRegistry::class, 'payout_registry_id');
    }
}

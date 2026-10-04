<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ST-04: one operation on the client cabinet balance [assumption Q-52].
 * type credit: credited; type spend: spend_reserved → spent | spend_reversed;
 * type withdraw: withdraw_reserved → withdraw_processing → withdrawn | withdraw_review → withdrawn | withdraw_cancelled.
 */
class ClientBalanceOperation extends Model
{
    use HasStateMachine, HasUuids;

    protected static string $eventPrefix = 'pay.balance';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return ['is_certificate_funds' => 'boolean'];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            'credited' => [],
            'spend_reserved' => ['spent', 'spend_reversed'],
            'spent' => [],
            'spend_reversed' => [],
            'withdraw_reserved' => ['withdraw_processing', 'withdraw_cancelled'],
            'withdraw_processing' => ['withdrawn', 'withdraw_review'],
            'withdraw_review' => ['withdrawn', 'withdraw_cancelled'],
            'withdrawn' => [],
            'withdraw_cancelled' => [],
        ]];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}

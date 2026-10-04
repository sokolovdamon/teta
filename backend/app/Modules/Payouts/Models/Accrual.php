<?php

namespace App\Modules\Payouts\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** ST-06: accrual to a psychologist or supervisor, 70 % of the full fixed price (DEC-57). */
class Accrual extends Model
{
    use HasStateMachine, HasUuids, UtcDates;

    protected static string $eventPrefix = 'payout.accrual';

    protected $guarded = ['id', 'status'];

    protected static function transitions(): array
    {
        return ['status' => [
            'accrued' => ['accrued', 'reversed', 'in_registry'],
            'in_registry' => ['accrued', 'paid'],
            'paid' => ['corrected'],
            'reversed' => [],
            'corrected' => [],
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

    public function net(): int
    {
        return $this->amount - $this->reversed_amount;
    }
}

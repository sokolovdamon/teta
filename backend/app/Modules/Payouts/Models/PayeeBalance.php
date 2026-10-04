<?php

namespace App\Modules\Payouts\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Materialized balance of a psychologist or supervisor, net of the platform commission (DEC-20).
 * Recomputed from the accrual ledger in the same transaction as every ledger change (PayeeBalanceService).
 */
class PayeeBalance extends Model
{
    use UtcDates;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payouts_suspended' => 'boolean', 'suspended_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}

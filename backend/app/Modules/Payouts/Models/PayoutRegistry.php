<?php

namespace App\Modules\Payouts\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutRegistry extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'approved_at' => 'datetime', 'auto_approve' => 'boolean'];
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }
}

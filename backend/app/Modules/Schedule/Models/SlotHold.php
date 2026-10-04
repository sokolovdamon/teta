<?php

namespace App\Modules\Schedule\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** P-SLOT-HOLD: slot held while the client completes registration and card binding. */
class SlotHold extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}

<?php

namespace App\Modules\Notifications\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }
}

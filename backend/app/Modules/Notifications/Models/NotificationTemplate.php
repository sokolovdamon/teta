<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'send_email' => 'boolean',
            'send_center' => 'boolean',
            'is_transactional' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}

<?php

namespace App\Modules\Payments\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Webhooks are verified by signature and written here before processing. */
class WebhookInbox extends Model
{
    use HasUuids, UtcDates;

    protected $table = 'webhook_inbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'signature_valid' => 'boolean', 'processed_at' => 'datetime'];
    }
}

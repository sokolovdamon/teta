<?php

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Webhooks are verified by signature and written here before processing. */
class WebhookInbox extends Model
{
    use HasUuids;

    protected $table = 'webhook_inbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'signature_valid' => 'boolean', 'processed_at' => 'datetime'];
    }
}

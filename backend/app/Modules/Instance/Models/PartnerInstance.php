<?php

namespace App\Modules\Instance\Models;

use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ST-20: registry record of a partner instance, kept in the main instance (ADM-21). */
class PartnerInstance extends Model
{
    use HasStateMachine, HasUuids, UtcDates;

    protected static string $eventPrefix = 'instance.partner';

    protected $guarded = ['id', 'status', 'heartbeat_token'];

    protected $hidden = ['heartbeat_token'];

    protected function casts(): array
    {
        return ['branding' => 'array', 'launched_at' => 'datetime', 'last_heartbeat_at' => 'datetime'];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            'registered' => ['deploying'],
            'deploying' => ['registered', 'configuring'],
            'configuring' => ['operational'],
            'operational' => ['unavailable', 'updating', 'offboarding'],
            'unavailable' => ['operational', 'offboarding'],
            'updating' => ['operational'],
            'offboarding' => ['archived'],
            'archived' => [],
        ]];
    }
}

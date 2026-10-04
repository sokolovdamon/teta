<?php

namespace App\Support\Events;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DomainEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime', 'dispatched_at' => 'datetime'];
    }
}

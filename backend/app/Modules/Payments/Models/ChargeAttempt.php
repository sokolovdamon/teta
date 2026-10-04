<?php

namespace App\Modules\Payments\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ChargeAttempt extends Model
{
    use HasUuids, UtcDates;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}

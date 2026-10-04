<?php

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ChargeAttempt extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}

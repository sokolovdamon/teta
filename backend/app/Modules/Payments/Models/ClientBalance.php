<?php

namespace App\Modules\Payments\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Model;

/** Materialized client balance; recomputed from operations in the same transaction. */
class ClientBalance extends Model
{
    use UtcDates;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

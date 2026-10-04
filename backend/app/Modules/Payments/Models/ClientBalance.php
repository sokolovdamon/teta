<?php

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;

/** Materialized client balance; recomputed from operations in the same transaction. */
class ClientBalance extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

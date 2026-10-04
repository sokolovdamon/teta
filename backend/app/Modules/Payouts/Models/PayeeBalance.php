<?php

namespace App\Modules\Payouts\Models;

use Illuminate\Database\Eloquent\Model;

class PayeeBalance extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payouts_suspended' => 'boolean'];
    }
}

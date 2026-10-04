<?php

namespace App\Modules\Promo\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** DEC-42: the friend gets a first-session code; the inviter gets a code after the friend's first paid session. */
class ReferralInvite extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];
}

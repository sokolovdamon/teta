<?php

namespace App\Modules\Promo\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** DEC-42: the friend gets a first-session code; the inviter gets a code after the friend's first paid session. */
class ReferralInvite extends Model
{
    use HasUuids;

    protected $guarded = ['id'];
}

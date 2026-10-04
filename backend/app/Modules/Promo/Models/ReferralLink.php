<?php

namespace App\Modules\Promo\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Personal invite code of a client (CL-13); the link is /auth/register?ref={code}. */
class ReferralLink extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Modules\Promo\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** reserved (at booking) → applied (charged) | restored (cancelled before charge, BR-PROMO-09). */
class PromoRedemption extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }
}

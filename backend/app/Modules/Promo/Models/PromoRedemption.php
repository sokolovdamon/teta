<?php

namespace App\Modules\Promo\Models;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** reserved (at booking) → applied (charged) | restored (cancelled before charge, BR-PROMO-09). */
class PromoRedemption extends Model
{
    use HasUuids, UtcDates;

    public const LIVE = ['reserved', 'applied'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'restored_at' => 'datetime'];
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'therapy_session_id');
    }
}

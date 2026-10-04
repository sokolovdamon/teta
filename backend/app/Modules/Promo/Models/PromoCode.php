<?php

namespace App\Modules\Promo\Models;

use App\Support\Database\UtcDates;
use App\Support\StateMachine\HasStateMachine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ST-17 (Э8). Types: percent, fixed, first_session. The discount reduces only the platform share:
 * the psychologist always gets 70 % of the full price (DEC-57).
 */
class PromoCode extends Model
{
    use HasStateMachine, HasUuids, UtcDates;

    protected static string $eventPrefix = 'promo.code';

    protected $guarded = ['id', 'status'];

    protected function casts(): array
    {
        return ['valid_from' => 'datetime', 'valid_until' => 'datetime', 'restrictions' => 'array'];
    }

    protected static function transitions(): array
    {
        return ['status' => [
            'draft' => ['scheduled', 'active', 'deactivated'],
            'scheduled' => ['active', 'deactivated'],
            'active' => ['active', 'exhausted', 'expired', 'deactivated'],
            'exhausted' => ['active', 'expired'],
            'expired' => [],
            'deactivated' => [],
        ]];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    /** Discount in kopecks for the given price. */
    public function discountFor(int $price): int
    {
        $discount = match ($this->type) {
            'fixed' => (int) $this->value,
            default => intdiv($price * (int) $this->value, 100),
        };

        return max(0, min($price, $discount));
    }
}

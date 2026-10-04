<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Card token from the payment gateway; card numbers are never stored (TZ v2, section 8). */
class PaymentMethod extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'removed_at' => 'datetime', 'token' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id, 'card_mask' => $this->card_mask, 'card_brand' => $this->card_brand,
            'exp_month' => $this->exp_month, 'exp_year' => $this->exp_year, 'purpose' => $this->purpose, 'is_default' => $this->is_default,
        ];
    }
}

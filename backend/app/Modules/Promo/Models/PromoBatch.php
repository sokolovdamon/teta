<?php

namespace App\Modules\Promo\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Batch of N unique individual codes with the same terms (ADM-09). */
class PromoBatch extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    public function codes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}

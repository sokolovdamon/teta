<?php

namespace App\Modules\Promo\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoBatch extends Model
{
    use HasUuids, UtcDates;

    protected $guarded = ['id'];

    public function codes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }
}

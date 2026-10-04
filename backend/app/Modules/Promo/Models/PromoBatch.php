<?php

namespace App\Modules\Promo\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoBatch extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function codes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }
}

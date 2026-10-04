<?php

namespace App\Modules\Dictionaries\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PriceCategory extends Model
{
    use HasUuids, UtcDates;

    protected $table = 'price_categories';

    protected $guarded = ['id'];

    /** DEC-55: the category is determined by the price of an individual session. */
    public static function forPrice(?int $price): ?self
    {
        if ($price === null) {
            return null;
        }

        return static::orderBy('sort')->get()->first(fn (self $c) => $price >= $c->min_price && ($c->max_price === null || $price <= $c->max_price));
    }
}

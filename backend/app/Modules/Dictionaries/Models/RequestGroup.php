<?php

namespace App\Modules\Dictionaries\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestGroup extends Model
{
    use HasUuids, UtcDates;

    protected $table = 'request_groups';

    protected $guarded = ['id'];

    public function requests(): HasMany
    {
        return $this->hasMany(ClientRequest::class)->orderBy('carousel_sort');
    }
}

<?php

namespace App\Modules\Dictionaries\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Approach extends Model
{
    use HasUuids, UtcDates;

    protected $table = 'approaches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

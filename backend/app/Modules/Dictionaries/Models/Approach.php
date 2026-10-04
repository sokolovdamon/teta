<?php

namespace App\Modules\Dictionaries\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Approach extends Model
{
    use HasUuids;

    protected $table = 'approaches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

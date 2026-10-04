<?php

namespace App\Modules\Dictionaries\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Specialization extends Model
{
    use HasUuids;

    protected $table = 'specializations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

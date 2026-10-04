<?php

namespace App\Modules\Rbac\Models;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasUuids, UtcDates;

    protected $fillable = ['code', 'section', 'action', 'title'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}

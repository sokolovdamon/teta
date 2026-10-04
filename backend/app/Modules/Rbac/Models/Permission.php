<?php

namespace App\Modules\Rbac\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'section', 'action', 'title'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}

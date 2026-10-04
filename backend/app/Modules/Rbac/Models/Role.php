<?php

namespace App\Modules\Rbac\Models;

use App\Models\User;
use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasUuids, UtcDates;

    public const CLIENT = 'client';

    public const PSYCHOLOGIST = 'psychologist';

    public const SUPERVISOR = 'supervisor';

    public const ADMIN = 'admin';

    public const SUPER_ADMIN = 'super_admin';

    public const HR = 'hr';

    protected $fillable = ['code', 'title', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public static function byCode(string $code): self
    {
        return static::where('code', $code)->firstOrFail();
    }
}

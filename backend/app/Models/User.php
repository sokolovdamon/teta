<?php

namespace App\Models;

use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Rbac\Models\Role;
use App\Support\Database\UtcDates;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $email
 * @property string $name
 * @property string|null $last_name
 * @property string $timezone
 * @property string $status
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes, UtcDates;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_PENDING_DELETION = 'pending_deletion';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = ['email', 'password', 'name', 'last_name', 'phone', 'birth_date', 'gender', 'timezone'];

    protected $hidden = ['password', 'remember_token'];

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
            'blocked_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('assigned_by', 'created_at');
    }

    public function psychologist(): HasOne
    {
        return $this->hasOne(Psychologist::class);
    }

    /** @return list<string> */
    public function roleCodes(): array
    {
        return $this->roles->pluck('code')->values()->all();
    }

    public function hasRole(string ...$codes): bool
    {
        return $this->roles->pluck('code')->intersect($codes)->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /** @return list<string> */
    public function permissionCodes(): array
    {
        if ($this->permissionCache === null) {
            $this->loadMissing('roles.permissions');
            $this->permissionCache = $this->roles
                ->flatMap(fn (Role $role) => $role->permissions->pluck('code'))
                ->unique()->values()->all();
        }

        return $this->permissionCache;
    }

    public function hasPermission(string $code): bool
    {
        return $this->isSuperAdmin() || in_array($code, $this->permissionCodes(), true);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('roles');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function fullName(): string
    {
        return trim($this->name.' '.($this->last_name ?? ''));
    }

    public function isAdult(): bool
    {
        return $this->birth_date !== null && $this->birth_date->diffInYears(now()) >= 18;
    }
}

<?php

namespace App\Modules\Payments\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/** Active administrators who should receive a work notification (by RBAC permission, super admins always). */
final class AdminRecipients
{
    /** @return Collection<int, User> */
    public static function withPermission(string $permission): Collection
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles', fn ($q) => $q->where('code', 'super_admin')
                ->orWhereHas('permissions', fn ($p) => $p->where('code', $permission)))
            ->with('roles')
            ->get();
    }

    /** @return Collection<int, User> */
    public static function superAdmins(): Collection
    {
        return User::query()->where('status', User::STATUS_ACTIVE)->whereHas('roles', fn ($q) => $q->where('code', 'super_admin'))->get();
    }
}

<?php

namespace App\Modules\Psychologists\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Active administrators holding an RBAC permission (super administrators hold every permission). */
class AdminRecipients
{
    /** @return Collection<int, User> */
    public static function withPermission(string $code): Collection
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->where(fn (Builder $q) => $q
                ->whereHas('roles', fn (Builder $r) => $r->where('code', 'super_admin'))
                ->orWhereHas('roles.permissions', fn (Builder $perm) => $perm->where('code', $code)))
            ->get();
    }
}

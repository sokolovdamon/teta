<?php

namespace App\Modules\Rbac\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use Illuminate\Support\Facades\DB;

class RbacService
{
    /** Create or update roles and the permission catalogue from config/rbac.php (idempotent). */
    public function syncCatalogue(bool $applyDefaultGrants = true): void
    {
        DB::transaction(function () use ($applyDefaultGrants) {
            foreach (config('rbac.roles') as $code => $role) {
                Role::updateOrCreate(['code' => $code], ['title' => $role['title'], 'description' => $role['description'], 'is_system' => true]);
            }

            $codes = [];
            foreach (config('rbac.sections') as $section => $def) {
                foreach ($def['actions'] as $action) {
                    $code = "{$def['code']}.{$action}";
                    $codes[] = $code;
                    Permission::updateOrCreate(['code' => $code], [
                        'section' => $section,
                        'action' => $action,
                        'title' => $def['title'].': '.(config("rbac.action_titles.{$action}") ?? $action),
                    ]);
                }
            }
            Permission::whereNotIn('code', $codes)->delete();

            if ($applyDefaultGrants) {
                $admin = Role::byCode(Role::ADMIN);
                if ($admin->permissions()->count() === 0) {
                    $except = config('rbac.defaults.admin.except', []);
                    $admin->permissions()->sync(Permission::whereNotIn('code', $except)->pluck('id'));
                }
            }
        });
    }

    /** Replace a role's permissions (matrix edit in ADM-05). */
    public function setRolePermissions(Role $role, array $permissionCodes, ?User $actor = null): void
    {
        $before = $role->permissions()->pluck('code')->sort()->values()->all();
        $ids = Permission::whereIn('code', $permissionCodes)->pluck('id');
        $role->permissions()->sync($ids);
        $after = $role->permissions()->pluck('code')->sort()->values()->all();

        Audit::log('ADM-05', 'role.permissions_updated', $role, [
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        ], userId: $actor?->id);
    }

    /** @param  list<string>  $roleCodes */
    public function setUserRoles(User $user, array $roleCodes, ?User $actor = null): void
    {
        $before = $user->roles()->pluck('code')->sort()->values()->all();
        $roles = Role::whereIn('code', $roleCodes)->get();
        $user->roles()->sync($roles->mapWithKeys(fn ($r) => [$r->id => ['assigned_by' => $actor?->id, 'created_at' => now()]])->all());
        $user->flushPermissionCache();

        Audit::log('ADM-02', 'user.roles_changed', $user, ['before' => $before, 'after' => $roles->pluck('code')->sort()->values()->all()], userId: $actor?->id);
    }

    public function assignRole(User $user, string $roleCode, ?User $actor = null): void
    {
        $role = Role::byCode($roleCode);
        $user->roles()->syncWithoutDetaching([$role->id => ['assigned_by' => $actor?->id, 'created_at' => now()]]);
        $user->flushPermissionCache();
    }
}

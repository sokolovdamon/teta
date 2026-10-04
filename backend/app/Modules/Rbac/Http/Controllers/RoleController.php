<?php

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Audit;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Services\RbacService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** ADM-05: roles and the "role — section — action" matrix, edited without code changes. */
class RoleController extends Controller
{
    public function __construct(private RbacService $rbac) {}

    public function matrix()
    {
        $roles = Role::with('permissions:id,code')->withCount('users')->orderBy('created_at')->get();
        $sections = collect(config('rbac.sections'))->map(fn ($def, $id) => [
            'id' => $id,
            'title' => $def['title'],
            'permissions' => collect($def['actions'])->map(fn ($a) => [
                'code' => "{$def['code']}.{$a}",
                'action' => $a,
                'title' => config("rbac.action_titles.{$a}") ?? $a,
            ])->values(),
        ])->values();

        return response()->json([
            'roles' => $roles->map(fn (Role $r) => [
                'id' => $r->id,
                'code' => $r->code,
                'title' => $r->title,
                'description' => $r->description,
                'is_system' => $r->is_system,
                'users_count' => $r->users_count,
                'all_permissions' => $r->code === Role::SUPER_ADMIN,
                'permissions' => $r->permissions->pluck('code')->values(),
            ]),
            'sections' => $sections,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', Rule::unique('roles', 'code')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'code')],
        ]);
        $role = Role::create([...collect($data)->only(['code', 'title', 'description'])->all(), 'is_system' => false]);
        $this->rbac->setRolePermissions($role, $data['permissions'] ?? [], $request->user());
        Audit::log('ADM-05', 'role.created', $role, ['code' => $role->code]);

        return response()->json(['data' => $role], 201);
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'code')],
        ]);
        abort_if($role->code === Role::SUPER_ADMIN && array_key_exists('permissions', $data), 422, 'У супер-администратора все права, их нельзя менять.');

        $role->fill(collect($data)->only(['title', 'description'])->all())->save();
        if (array_key_exists('permissions', $data)) {
            $this->rbac->setRolePermissions($role, $data['permissions'], $request->user());
        }

        return response()->json(['data' => $role->load('permissions:id,code')]);
    }

    public function destroy(Role $role)
    {
        abort_if($role->is_system, 422, 'Системную роль удалить нельзя.');
        abort_if($role->users()->exists(), 422, 'Роль назначена пользователям — сначала снимите её.');
        Audit::log('ADM-05', 'role.deleted', $role, ['code' => $role->code]);
        $role->delete();

        return response()->noContent();
    }

    public function permissions()
    {
        return response()->json(['data' => Permission::orderBy('section')->orderBy('code')->get(['code', 'section', 'action', 'title'])]);
    }
}

<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Rbac\Models\Role;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacAndAdminTest extends TestCase
{
    public function test_client_cannot_open_admin_sections(): void
    {
        $this->actingAsRole('client');
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/roles')->assertForbidden();
    }

    public function test_admin_has_default_permissions_but_not_roles_management(): void
    {
        $this->actingAsRole('admin');
        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->getJson('/api/v1/admin/roles')->assertOk();
        $this->postJson('/api/v1/admin/roles', ['code' => 'moderator', 'title' => 'Модератор'])->assertForbidden();
    }

    public function test_super_admin_edits_matrix_and_creates_role(): void
    {
        $this->actingAsRole('super_admin');
        $this->postJson('/api/v1/admin/roles', [
            'code' => 'moderator', 'title' => 'Модератор', 'permissions' => ['admin.moderation.view', 'admin.moderation.moderate'],
        ])->assertCreated();

        $role = Role::where('code', 'moderator')->firstOrFail();
        $this->assertEqualsCanonicalizing(['admin.moderation.view', 'admin.moderation.moderate'], $role->permissions()->pluck('code')->all());

        $moderator = $this->userWithRole('moderator');
        Sanctum::actingAs($moderator);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/consents')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['section' => 'ADM-05', 'action' => 'role.permissions_updated']);
    }

    public function test_system_role_cannot_be_deleted(): void
    {
        $this->actingAsRole('super_admin');
        $this->deleteJson('/api/v1/admin/roles/'.Role::byCode('client')->id)->assertUnprocessable();
    }

    public function test_blocking_user_revokes_tokens_and_is_audited(): void
    {
        $target = $this->userWithRole('client');
        $token = $target->createToken('web')->plainTextToken;

        $this->actingAsRole('admin');
        $this->postJson("/api/v1/admin/users/{$target->id}/block", ['reason' => 'Нарушение правил'])->assertOk()->assertJsonPath('data.status', 'blocked');

        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['section' => 'ADM-02', 'action' => 'user.blocked', 'subject_id' => $target->id]);
        $this->assertDatabaseHas('domain_events', ['name' => 'account.user.blocked', 'aggregate_id' => $target->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_only_super_admin_grants_super_admin(): void
    {
        $target = $this->userWithRole('client');
        $this->actingAsRole('admin');
        $this->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['super_admin']])->assertForbidden();
        $this->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['client', 'hr']])->assertOk()->assertJsonPath('data.roles', fn ($r) => count($r) === 2);
    }

    public function test_user_search_and_filters(): void
    {
        User::factory()->withRole('psychologist')->create(['email' => 'find-me@example.com', 'name' => 'Зоя']);
        $this->actingAsRole('admin');
        $this->getJson('/api/v1/admin/users?q=find-me&role=psychologist')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/audit')->assertOk();
    }
}

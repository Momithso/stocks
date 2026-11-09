<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * The grant rule: nobody can grant a permission he does not have himself.
 */
class RoleManagementTest extends TestCase
{
    public function test_a_role_can_be_created_with_own_permissions(): void
    {
        $actor = $this->userWithPermissions('roles.manage', 'users.view');

        $this->actingAs($actor)
            ->post('/admin/roles', ['name' => 'Kassenwart', 'permissions' => ['users.view']])
            ->assertRedirect('/admin/roles');

        $this->assertTrue(Role::findByName('Kassenwart')->hasPermissionTo('users.view'));
    }

    public function test_a_role_can_not_get_a_permission_the_actor_does_not_have(): void
    {
        $actor = $this->userWithPermissions('roles.manage');

        $this->actingAs($actor)
            ->post('/admin/roles', ['name' => 'Zu mächtig', 'permissions' => ['modules.manage']])
            ->assertSessionHasErrors('permissions');

        $this->assertDatabaseMissing('roles', ['name' => 'Zu mächtig']);
    }

    public function test_a_role_with_permissions_the_actor_does_not_have_can_not_be_changed_or_deleted(): void
    {
        $actor = $this->userWithPermissions('roles.manage');
        $role = Role::findByName('Vorstand');

        $this->actingAs($actor)
            ->put("/admin/roles/{$role->id}", ['name' => 'Vorstand', 'permissions' => []])
            ->assertSessionHasErrors('permissions');
        $this->actingAs($actor)->delete("/admin/roles/{$role->id}")->assertSessionHasErrors('role');

        $this->assertTrue($role->fresh()->hasPermissionTo('users.invite'));
    }

    public function test_the_admin_role_can_not_be_changed_or_deleted(): void
    {
        $admin = $this->admin();
        $role = Role::findByName(Role::admin());

        $this->actingAs($admin)->put("/admin/roles/{$role->id}", ['name' => 'Chef', 'permissions' => []])->assertSessionHasErrors('name');
        $this->actingAs($admin)->delete("/admin/roles/{$role->id}")->assertSessionHasErrors('role');

        $this->assertSame(Role::admin(), $role->fresh()->name);
    }

    public function test_roles_that_accounts_have_can_not_be_deleted(): void
    {
        $this->userWithRoles('Freies Mitglied');
        $role = Role::findByName('Freies Mitglied');

        $this->actingAs($this->admin())->delete("/admin/roles/{$role->id}")->assertSessionHasErrors('role');

        $role->users()->detach();
        $this->actingAs($this->admin())->delete("/admin/roles/{$role->id}")->assertRedirect('/admin/roles');
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_roles_are_given_and_taken_only_when_the_actor_could_grant_them(): void
    {
        $actor = $this->userWithRoles('Vorstand');
        $member = $this->userWithRoles('FSR-Mitglied');

        // Vorstand has every permission of "Freies Mitglied" (none)
        $this->actingAs($actor)
            ->put("/admin/users/{$member->id}", ['roles' => [Role::findByName('Freies Mitglied')->id], 'is_disabled' => false])
            ->assertSessionHas('success');
        $this->assertTrue($member->fresh()->hasRole('Freies Mitglied'));

        // but not the admin role
        $this->actingAs($actor)
            ->put("/admin/users/{$member->id}", ['roles' => [Role::findByName(Role::admin())->id], 'is_disabled' => false])
            ->assertSessionHasErrors('roles');
        $this->assertFalse($member->fresh()->hasRole(Role::admin()));
    }

    public function test_accounts_with_more_permissions_than_the_actor_can_not_be_managed(): void
    {
        $actor = $this->userWithRoles('Vorstand');
        $admin = $this->admin();

        $this->actingAs($actor)->put("/admin/users/{$admin->id}", ['roles' => [], 'is_disabled' => true])->assertForbidden();
        $this->actingAs($actor)->post("/admin/users/{$admin->id}/reset-pin")->assertForbidden();

        $this->assertFalse($admin->fresh()->is_disabled);
    }

    public function test_nobody_changes_his_own_roles(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", ['roles' => [], 'is_disabled' => false])->assertForbidden();
    }

    public function test_the_last_admin_stays_admin(): void
    {
        $admin = $this->admin();
        // has every permission without being admin, so he could manage the admin
        $almighty = $this->userWithPermissions(...Role::findByName(Role::admin())->permissions->pluck('name'));

        $this->actingAs($almighty)->put("/admin/users/{$admin->id}", ['roles' => [], 'is_disabled' => false])->assertSessionHasErrors('roles');
        $this->actingAs($almighty)->put("/admin/users/{$admin->id}", ['roles' => [Role::findByName(Role::admin())->id], 'is_disabled' => true])->assertSessionHasErrors('roles');
        $this->actingAs($almighty)->delete("/admin/users/{$admin->id}")->assertSessionHasErrors('user');

        $this->assertTrue($admin->fresh()->hasRole(Role::admin()));
        $this->assertFalse($admin->fresh()->is_disabled);
    }

    public function test_role_changes_are_audited(): void
    {
        $this->enableModelAuditing();
        $member = $this->userWithRoles('FSR-Mitglied');

        $this->actingAs($this->admin())->put("/admin/users/{$member->id}", ['roles' => [Role::findByName('Vorstand')->id], 'is_disabled' => false]);

        $this->assertDatabaseHas('audits', ['event' => 'rolesUpdated', 'auditable_id' => $member->id, 'auditable_type' => User::class]);
    }

    public function test_the_role_form_marks_permissions_the_actor_does_not_have(): void
    {
        $this->actingAs($this->userWithPermissions('roles.manage', 'users.view'))
            ->get('/admin/roles/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Roles/Edit')
                ->where('ownPermissions', ['roles.manage', 'users.view'])
                ->where('permissionGroups.0.group', 'Kern'));
    }
}

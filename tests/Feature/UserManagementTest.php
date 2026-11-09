<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Factories\UserFactory;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    public function test_the_list_needs_the_permission(): void
    {
        $this->actingAs($this->userWithRoles('Freies Mitglied'))->get('/admin/users')->assertForbidden();
        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->get('/admin/users')->assertOk();
    }

    public function test_the_list_is_loaded_page_by_page_with_search_and_sorting(): void
    {
        $admin = $this->admin();
        User::factory()->count(60)->create();
        User::factory()->create(['firstname' => 'Xylophonia', 'lastname' => 'Zzzuletzt']);

        $this->actingAs($admin)->get('/admin/users')
            ->assertInertia(fn ($page) => $page->component('Admin/Users/Index')->has('users.data', 50));

        $this->actingAs($admin)->get('/admin/users?search=xylophon')
            ->assertInertia(fn ($page) => $page->has('users.data', 1)->where('users.data.0.lastname', 'Zzzuletzt'));

        $this->actingAs($admin)->get('/admin/users?sort=lastname&direction=desc')
            ->assertInertia(fn ($page) => $page->where('users.data.0.lastname', 'Zzzuletzt'));
    }

    public function test_the_live_update_reports_changed_removed_and_new_accounts(): void
    {
        $admin = $this->admin();
        $changed = User::factory()->create();
        $removed = User::factory()->create();
        $since = now()->subSecond()->timestamp;

        $changed->update(['firstname' => 'Geändert']);
        $removed->delete();
        $new = User::factory()->create();

        $this->actingAs($admin)
            ->getJson("/admin/users?changes_since={$since}&loaded_ids={$changed->id},{$removed->id}")
            ->assertOk()
            ->assertJsonPath('rows.0.firstname', 'Geändert')
            ->assertJsonPath('removed_ids', [$removed->id])
            ->assertJsonFragment(['new_ids' => [$admin->id, $new->id]]);
    }

    public function test_an_account_can_be_disabled_and_deleted(): void
    {
        $admin = $this->admin();
        $member = $this->userWithRoles('FSR-Mitglied');

        $this->actingAs($admin)->put("/admin/users/{$member->id}", ['roles' => [Role::findByName('FSR-Mitglied')->id], 'is_disabled' => true]);
        $this->assertTrue($member->fresh()->is_disabled);

        $this->actingAs($admin)->delete("/admin/users/{$member->id}")->assertSessionHas('success');
        $this->assertModelMissing($member);
    }

    public function test_deleting_needs_its_own_permission(): void
    {
        $member = $this->userWithRoles('FSR-Mitglied');

        $this->actingAs($this->userWithRoles('Vorstand'))->delete("/admin/users/{$member->id}")->assertForbidden();
        $this->assertModelExists($member);
    }

    public function test_the_pin_can_only_be_reset(): void
    {
        $member = User::factory()->withPin()->create();

        $this->enableModelAuditing();
        $this->actingAs($this->admin())->post("/admin/users/{$member->id}/reset-pin")->assertSessionHas('success');

        $this->assertFalse($member->fresh()->hasPin());
        $this->assertDatabaseHas('audits', ['event' => 'pinReset', 'auditable_id' => $member->id]);
    }

    public function test_password_pin_and_tokens_never_reach_the_browser(): void
    {
        $admin = $this->admin();
        User::factory()->withPin()->create();

        $content = $this->actingAs($admin)->get('/admin/users')->getContent();

        $this->assertStringNotContainsString('"password"', $content);
        $this->assertStringNotContainsString('"pin"', $content);
        $this->assertStringNotContainsString('remember_token', $content);
        $this->assertStringNotContainsString(UserFactory::PIN, $content);
    }
}

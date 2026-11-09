<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    public function test_inviting_needs_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions('users.view'))
            ->post('/admin/users/invite', ['email' => 'new@example.com'])
            ->assertForbidden();
    }

    public function test_an_invitation_sends_a_link_that_creates_the_account_with_its_roles(): void
    {
        Notification::fake();
        $role = Role::findByName('FSR-Mitglied');

        $this->actingAs($this->admin())
            ->post('/admin/users/invite', ['email' => 'New@Example.com', 'roles' => [$role->id]])
            ->assertSessionHas('success');

        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use (&$token) {
            $token = $notification->token;

            return $notifiable->routes['mail'] === 'new@example.com';
        });

        auth()->logout();
        $this->get("/invitation/{$token}")->assertOk()->assertInertia(fn ($page) => $page->where('email', 'new@example.com'));

        $this->post("/invitation/{$token}", [
            'firstname' => 'Neue',
            'lastname' => 'Person',
            'password' => 'a very long password',
            'password_confirmation' => 'a very long password',
        ])->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole('FSR-Mitglied'));

        // the link works only once
        auth()->logout();
        $this->get("/invitation/{$token}")->assertNotFound();
    }

    public function test_expired_invitations_do_not_work(): void
    {
        Invitation::factory()->withToken('expired-token')->expired()->create();

        $this->get('/invitation/expired-token')->assertNotFound();
        $this->post('/invitation/expired-token', [])->assertNotFound();
    }

    public function test_a_new_invitation_replaces_the_older_one(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/users/invite', ['email' => 'twice@example.com']);
        $this->actingAs($admin)->post('/admin/users/invite', ['email' => 'twice@example.com']);

        $this->assertSame(1, Invitation::open()->where('email', 'twice@example.com')->count());
    }

    public function test_nobody_can_invite_with_a_role_he_could_not_grant(): void
    {
        Notification::fake();
        $inviter = $this->userWithPermissions('users.view', 'users.invite', 'roles.manage');

        $this->actingAs($inviter)
            ->post('/admin/users/invite', ['email' => 'new@example.com', 'roles' => [Role::findByName(Role::admin())->id]])
            ->assertSessionHasErrors('roles');

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_inviting_with_roles_needs_the_role_permission(): void
    {
        Notification::fake();
        $inviter = $this->userWithPermissions('users.view', 'users.invite');

        $this->actingAs($inviter)
            ->post('/admin/users/invite', ['email' => 'new@example.com', 'roles' => [Role::findByName('Freies Mitglied')->id]])
            ->assertSessionHasErrors('roles');

        $this->actingAs($inviter)->post('/admin/users/invite', ['email' => 'new@example.com'])->assertSessionHas('success');
    }

    public function test_existing_accounts_can_not_be_invited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin())->post('/admin/users/invite', ['email' => $user->email])->assertSessionHasErrors('email');
    }
}

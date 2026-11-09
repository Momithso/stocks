<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_login_needs_the_right_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => strtoupper($user->email), 'password' => UserFactory::PASSWORD])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_disabled_accounts_can_not_log_in(): void
    {
        $user = User::factory()->disabled()->create();

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logins_are_limited_after_five_failures(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong password']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertSessionHasErrors(['email' => 'Zu viele Versuche. Bitte warte 60 Sekunden.']);
        $this->assertGuest();
    }

    public function test_a_disabled_account_loses_its_running_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_disabled' => true]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_logout(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}

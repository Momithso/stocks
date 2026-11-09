<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    public function test_the_answer_is_the_same_for_known_and_unknown_addresses(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('success')->getSession()->get('success');
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com'])->getSession()->get('success');

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_a_new_password_can_be_set_with_the_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a new long password',
                'password_confirmation' => 'a new long password',
            ])->assertRedirect('/login');

            return true;
        });

        $this->assertTrue(Hash::check('a new long password', $user->fresh()->password));
    }

    public function test_short_passwords_are_rejected(): void
    {
        $this->post('/reset-password', [
            'token' => 'token',
            'email' => 'someone@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }
}

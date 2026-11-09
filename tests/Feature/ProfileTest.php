<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    public function test_the_name_can_be_changed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/profile', ['firstname' => 'Neu', 'lastname' => 'Name'])->assertSessionHas('success');

        $this->assertSame('Neu Name', $user->fresh()->name);
    }

    public function test_a_new_password_needs_the_current_one(): void
    {
        $user = User::factory()->create();
        $data = ['firstname' => 'A', 'lastname' => 'B', 'password' => 'another long password', 'password_confirmation' => 'another long password'];

        $this->actingAs($user)->put('/profile', [...$data, 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/profile', $data)->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/profile', [...$data, 'current_password' => UserFactory::PASSWORD])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('another long password', $user->fresh()->password));
    }

    public function test_the_pin_has_six_digits_and_is_not_easy_to_guess(): void
    {
        $user = User::factory()->create();
        $put = fn (string $pin) => $this->actingAs($user)->put('/profile', ['firstname' => 'A', 'lastname' => 'B', 'pin' => $pin, 'pin_confirmation' => $pin]);

        foreach (['1234', '12345a', '000000', '123456', '987654', '121212'] as $pin) {
            $put($pin)->assertSessionHasErrors('pin');
        }

        $put('482915')->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->isPinValid('482915'));
        $this->assertNotSame('482915', $user->fresh()->getRawOriginal('pin'));
    }
}

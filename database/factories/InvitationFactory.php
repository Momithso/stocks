<?php

namespace Database\Factories;

use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Invitations of the factory have a random token; use withToken() to know it.
 *
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(48)),
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Invitation whose link contains the given token.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => hash('sha256', $token)]);
    }

    /**
     * Expired invitation.
     */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}

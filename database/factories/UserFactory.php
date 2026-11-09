<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Password of all factory accounts.
     *
     * @var string
     */
    public const PASSWORD = 'correct horse battery';

    /**
     * Kiosk pin of factory accounts with pin.
     *
     * @var string
     */
    public const PIN = '482915';

    /**
     * The password hash is computed once, hashing for every account slows down the tests.
     */
    private static ?string $passwordHash = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::$passwordHash ??= bcrypt(self::PASSWORD),
            'pin' => null,
            'is_disabled' => false,
        ];
    }

    /**
     * Account with the kiosk pin self::PIN.
     */
    public function withPin(): static
    {
        return $this->state(fn () => ['pin' => self::PIN]);
    }

    /**
     * Disabled account.
     */
    public function disabled(): static
    {
        return $this->state(fn () => ['is_disabled' => true]);
    }
}

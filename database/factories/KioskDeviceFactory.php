<?php

namespace Database\Factories;

use App\Models\KioskDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KioskDevice>
 */
class KioskDeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Tablet '.fake()->unique()->word(),
            'token' => KioskDevice::generateToken(),
        ];
    }
}

<?php

namespace Modules\Kiosk\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Kiosk\Enums\KioskPlatform;
use Modules\Kiosk\Models\KioskDevice;

/**
 * @extends Factory<KioskDevice>
 */
class KioskDeviceFactory extends Factory
{
    /**
     * The factory lies in the module, so the model is named here.
     *
     * @var class-string<KioskDevice>
     */
    protected $model = KioskDevice::class;

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
            'platform' => KioskPlatform::Other,
        ];
    }
}

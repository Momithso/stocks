<?php

namespace Database\Factories;

use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state: a room without address. The actions never create such a
     * room, but most tests only need a place where items may lie.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'type' => LocationType::Room,
            'code' => strtoupper(fake()->unique()->bothify('??##')),
            'name' => fake()->streetName(),
            'full_code' => fn (array $attributes) => $attributes['code'],
        ];
    }

    /**
     * An address, the top level of the tree.
     */
    public function address(): static
    {
        return $this->state(['type' => LocationType::Address]);
    }

    /**
     * A location one level below the given one.
     */
    public function in(Location $parent): static
    {
        return $this->state([
            'parent_id' => $parent->id,
            'type' => $parent->type->childType(),
            'full_code' => fn (array $attributes) => Location::fullCodeOf($parent, $parent->type->childType(), $attributes['code']),
        ]);
    }
}

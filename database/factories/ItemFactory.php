<?php

namespace Database\Factories;

use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####')),
            'description' => null,
            'default_price_cents' => fake()->numberBetween(500, 50000),
            'is_active' => true,
            'track_inventory' => false,
        ];
    }

    public function tracked(): static
    {
        return $this->state(fn () => ['track_inventory' => true]);
    }
}

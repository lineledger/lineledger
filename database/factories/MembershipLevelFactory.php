<?php

namespace Database\Factories;

use App\Enums\RecurrenceFrequency;
use App\Models\MembershipLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipLevel>
 */
class MembershipLevelFactory extends Factory
{
    protected $model = MembershipLevel::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Individual', 'Family', 'Corporate', 'Student', 'Senior']),
            'default_dues_cents' => fake()->numberBetween(2500, 50000),
            'billing_frequency' => RecurrenceFrequency::Annual->value,
            'is_active' => true,
        ];
    }
}

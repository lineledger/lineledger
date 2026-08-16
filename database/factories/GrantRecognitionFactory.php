<?php

namespace Database\Factories;

use App\Models\Grant;
use App\Models\GrantRecognition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GrantRecognition>
 */
class GrantRecognitionFactory extends Factory
{
    protected $model = GrantRecognition::class;

    public function definition(): array
    {
        return [
            'grant_id' => Grant::factory(),
            'recognition_date' => '2026-06-01',
            'amount_cents' => fake()->numberBetween(1000, 100000),
        ];
    }
}

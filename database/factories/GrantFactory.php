<?php

namespace Database\Factories;

use App\Models\Grant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Grant>
 */
class GrantFactory extends Factory
{
    protected $model = Grant::class;

    public function definition(): array
    {
        return [
            'grant_no' => 'GR-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'name' => fake()->words(3, true).' grant',
            'status' => 'draft',
            'award_amount_cents' => fake()->numberBetween(50000, 5000000),
            'is_restricted' => true,
            'recognition_method' => 'manual',
            'recognized_to_date_cents' => 0,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ];
    }
}

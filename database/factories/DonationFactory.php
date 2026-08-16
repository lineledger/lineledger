<?php

namespace Database\Factories;

use App\Models\Donation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donation>
 */
class DonationFactory extends Factory
{
    protected $model = Donation::class;

    public function definition(): array
    {
        return [
            'donation_no' => 'DON-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'status' => 'draft',
            'gift_type' => 'cash',
            'donation_date' => '2026-03-01',
            'amount_cents' => fake()->numberBetween(1000, 500000),
            'is_restricted' => false,
        ];
    }

    public function restricted(): static
    {
        return $this->state(fn () => ['is_restricted' => true]);
    }
}

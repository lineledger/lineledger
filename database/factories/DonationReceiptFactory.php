<?php

namespace Database\Factories;

use App\Models\DonationReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonationReceipt>
 */
class DonationReceiptFactory extends Factory
{
    protected $model = DonationReceipt::class;

    public function definition(): array
    {
        $amount = fake()->numberBetween(1000, 500000);

        return [
            'receipt_no' => 'DR-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'status' => 'draft',
            'gift_type' => 'cash',
            'gift_date' => '2026-01-15',
            'donor_name' => fake()->name(),
            'amount_cents' => $amount,
            'advantage_cents' => 0,
            'eligible_amount_cents' => $amount,
        ];
    }

    public function issued(): static
    {
        return $this->state(fn () => ['status' => 'issued', 'issued_date' => '2026-01-16']);
    }

    public function inKind(): static
    {
        return $this->state(fn () => ['gift_type' => 'in_kind', 'in_kind_description' => 'Donated equipment']);
    }

    public function voided(): static
    {
        return $this->state(fn () => ['status' => 'void', 'void_reason' => 'Test void']);
    }
}

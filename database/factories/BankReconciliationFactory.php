<?php

namespace Database\Factories;

use App\Enums\BankReconciliationStatus;
use App\Models\BankReconciliation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankReconciliation>
 */
class BankReconciliationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'statement_date' => now()->endOfMonth()->toDateString(),
            'beginning_balance_cents' => 0,
            'ending_balance_cents' => 0,
            'service_charge_cents' => 0,
            'interest_earned_cents' => 0,
            'status' => BankReconciliationStatus::InProgress->value,
            'marked_line_ids' => [],
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => BankReconciliationStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\StatementLineMatchStatus;
use App\Models\BankStatementLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankStatementLine>
 */
class BankStatementLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = $this->faker->numberBetween(-500000, 500000);

        return [
            'txn_date' => now()->toDateString(),
            'amount_cents' => $amount,
            'description' => $this->faker->sentence(3),
            'external_id' => null,
            'fingerprint' => $this->faker->unique()->sha1(),
            'match_status' => StatementLineMatchStatus::Unmatched->value,
        ];
    }
}

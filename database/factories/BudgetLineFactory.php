<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetLine>
 *
 * Callers must supply an `account_id` from a company's seeded chart of accounts.
 */
class BudgetLineFactory extends Factory
{
    protected $model = BudgetLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months["month_{$month}_cents"] = (int) fake()->numberBetween(0, 500000);
        }

        return [
            'budget_id' => Budget::factory(),
            'company_id' => Company::factory(),
            'line_order' => 0,
            ...$months,
        ];
    }
}

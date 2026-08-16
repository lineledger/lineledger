<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->words(2, true).' Budget',
            'fiscal_year' => (int) fake()->numberBetween(2023, 2027),
            'class_id' => null,
            'location_id' => null,
            'notes' => null,
        ];
    }
}

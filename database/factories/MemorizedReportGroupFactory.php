<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\MemorizedReportGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemorizedReportGroup>
 */
class MemorizedReportGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'sort_order' => 0,
        ];
    }
}

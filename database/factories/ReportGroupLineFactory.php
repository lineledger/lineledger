<?php

namespace Database\Factories;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\ReportGroup;
use App\Models\ReportGroupLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportGroupLine>
 */
class ReportGroupLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_group_id' => ReportGroup::factory(),
            'name' => fake()->words(2, true),
            'type' => AccountType::Asset,
            'subtype' => AccountSubtype::Bank,
            'sort_order' => 0,
            'is_passthrough' => false,
        ];
    }
}

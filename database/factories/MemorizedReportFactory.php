<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\MemorizedReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemorizedReport>
 */
class MemorizedReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'memorized_report_group_id' => null,
            'report_key' => 'reports.income-statement',
            'name' => fake()->words(2, true),
            'settings' => ['preset' => 'last_fiscal_year', 'reportTitle' => 'Saved P&L'],
            'sort_order' => 0,
        ];
    }
}

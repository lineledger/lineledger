<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ReportFavorite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportFavorite>
 */
class ReportFavoriteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'report_key' => 'reports.balance-sheet',
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\GridPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GridPreference>
 */
class GridPreferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'grid_key' => 'chart_of_accounts',
            'visible_columns' => ['subtype', 'balance'],
        ];
    }
}

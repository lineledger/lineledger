<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\NavPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NavPreference>
 */
class NavPreferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'item_key' => 'banking.transfers',
        ];
    }
}

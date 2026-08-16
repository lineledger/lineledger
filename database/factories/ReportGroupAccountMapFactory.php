<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use App\Models\ReportGroup;
use App\Models\ReportGroupAccountMap;
use App\Models\ReportGroupLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportGroupAccountMap>
 */
class ReportGroupAccountMapFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Accounts have no factory — they are seeded by the CompanyObserver when a
        // company is created. Stand up a company and borrow one of its accounts so
        // the factory works standalone; tests normally override all four keys.
        $company = Company::factory()->create();
        $account = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->first();

        return [
            'report_group_id' => ReportGroup::factory(),
            'report_group_line_id' => ReportGroupLine::factory(),
            'company_id' => $company->id,
            'account_id' => $account?->id,
        ];
    }
}

<?php

namespace App\Actions\Budgeting;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;

/**
 * Seeds a budget grid from a prior fiscal year's actual GL activity. For each
 * income/expense account it returns the natural-balance period change of every
 * fiscal month, so the form can pre-fill targets from "what actually happened
 * last year" — mirroring QuickBooks' create-from-actuals option.
 */
final class BuildBudgetFromActuals
{
    public function __construct(protected ReportCalculator $calculator) {}

    /**
     * Returns account_id => [1..12 => cents] for the fiscal year that precedes
     * $fiscalYear, optionally restricted to a class and/or location dimension.
     *
     * @return array<int, array<int, int>>
     */
    public function handle(Company $company, int $fiscalYear, ?int $classId = null, ?int $locationId = null): array
    {
        $startMonth = (int) ($company->fiscal_year_start_month ?? 1);
        $priorYearStart = CarbonImmutable::create($fiscalYear - 1, $startMonth, 1);

        $accounts = Account::query()
            ->where('company_id', $company->id)
            ->whereIn('type', [AccountType::Income->value, AccountType::Expense->value])
            ->orderBy('code')
            ->get();

        $result = [];

        foreach ($accounts as $account) {
            $months = [];

            for ($index = 1; $index <= 12; $index++) {
                $monthStart = $priorYearStart->addMonths($index - 1);
                $monthEnd = $monthStart->endOfMonth();

                $months[$index] = $this->calculator->periodChange(
                    $account,
                    $monthStart,
                    $monthEnd,
                    $classId,
                    $locationId,
                );
            }

            $result[(int) $account->id] = $months;
        }

        return $result;
    }
}

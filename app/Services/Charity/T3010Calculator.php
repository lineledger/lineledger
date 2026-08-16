<?php

namespace App\Services\Charity;

use App\Enums\AccountType;
use App\Enums\DonationReceiptStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\DonationReceipt;
use App\Services\Reporting\Form1099Calculator;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonInterface;

/**
 * Computes the financial figures a registered charity reports on the T3010
 * Registered Charity Information Return: receipted donations (from issued
 * receipts), total/other revenue and the expenditure split (charitable program /
 * management & admin / fundraising) from the GL, and the period-end balance-sheet
 * totals. Pure read service, mirroring {@see Form1099Calculator}.
 */
final class T3010Calculator
{
    public function __construct(protected ReportCalculator $calculator) {}

    /**
     * @return array{
     *   total_eligible_receipted_cents: int, total_revenue_cents: int, other_revenue_cents: int,
     *   charitable_program_cents: int, management_admin_cents: int, fundraising_cents: int,
     *   total_expenditures_cents: int, total_assets_cents: int, total_liabilities_cents: int, net_assets_cents: int,
     * }
     */
    public function summary(Company $company, CarbonInterface $start, CarbonInterface $end): array
    {
        $receipted = (int) DonationReceipt::query()
            ->where('company_id', $company->id)
            ->where('status', DonationReceiptStatus::Issued->value)
            ->where('is_consolidated', false)
            ->whereBetween('gift_date', [$start->toDateString(), $end->toDateString()])
            ->sum('eligible_amount_cents');

        $totalRevenue = $this->calculator->totalForType($company, AccountType::Income, $start, $end);

        $program = $admin = $fundraising = 0;
        $expenseAccounts = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', AccountType::Expense->value)
            ->get();

        foreach ($expenseAccounts as $account) {
            $amount = $this->calculator->periodChange($account, $start, $end);

            match ($this->bucketFor($account)) {
                'program' => $program += $amount,
                'fundraising' => $fundraising += $amount,
                default => $admin += $amount,
            };
        }

        $totalAssets = $this->calculator->totalForTypeAsOf($company, AccountType::Asset, $end);
        $totalLiabilities = $this->calculator->totalForTypeAsOf($company, AccountType::Liability, $end);

        return [
            'total_eligible_receipted_cents' => $receipted,
            'total_revenue_cents' => $totalRevenue,
            'other_revenue_cents' => $totalRevenue - $receipted,
            'charitable_program_cents' => $program,
            'management_admin_cents' => $admin,
            'fundraising_cents' => $fundraising,
            'total_expenditures_cents' => $program + $admin + $fundraising,
            'total_assets_cents' => $totalAssets,
            'total_liabilities_cents' => $totalLiabilities,
            'net_assets_cents' => $totalAssets - $totalLiabilities,
        ];
    }

    /**
     * Map an expense account to a T3010 expenditure bucket. Defaults from the
     * seeded non-profit chart codes, falling back to name keywords.
     */
    private function bucketFor(Account $account): string
    {
        $code = (string) $account->code;
        $name = mb_strtolower((string) $account->name);

        if ($code === '6310' || str_contains($name, 'fundrais')) {
            return 'fundraising';
        }

        if (in_array($code, ['6300', '6320'], true) || str_contains($name, 'program') || str_contains($name, 'grant')) {
            return 'program';
        }

        return 'admin';
    }
}

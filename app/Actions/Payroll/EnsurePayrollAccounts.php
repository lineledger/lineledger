<?php

namespace App\Actions\Payroll;

use App\Enums\Country;
use App\Enums\PayrollAccount;
use App\Models\Account;
use App\Models\Company;
use App\Support\Defaults\CanadianDefaults;

/**
 * Ensures the system payroll accounts (CPP/EI/Income-Tax/Vacation/Net-Pay-Clearing
 * payables + employer-cost expenses) exist for a company. New Canadian companies
 * get them via {@see CanadianDefaults}; this backfills any
 * that are missing — used by the backfill command and when payroll is enabled on
 * an existing company. Returns the number of accounts created.
 */
final class EnsurePayrollAccounts
{
    public function handle(Company $company): int
    {
        if ($company->jurisdiction !== Country::Canada) {
            return 0;
        }

        $created = 0;

        foreach (PayrollAccount::cases() as $payrollAccount) {
            $exists = Account::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('code', $payrollAccount->code())
                ->exists();

            if ($exists) {
                continue;
            }

            $subtype = $payrollAccount->subtype();

            Account::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'code' => $payrollAccount->code(),
                'name' => $payrollAccount->accountName(),
                'type' => $subtype->type(),
                'subtype' => $subtype,
                'normal_balance' => $subtype->type()->normalBalance(),
                'is_system' => $payrollAccount->isSystem(),
                'is_active' => true,
            ]);

            $created++;
        }

        return $created;
    }
}

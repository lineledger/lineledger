<?php

namespace App\Console\Commands;

use App\Actions\Payroll\EnsurePayrollAccounts;
use App\Enums\Country;
use App\Models\Company;
use Illuminate\Console\Command;

class BackfillPayrollAccountsCommand extends Command
{
    protected $signature = 'payroll:backfill-accounts {company? : Company ID or slug; all Canadian companies when omitted}';

    protected $description = 'Create the system payroll accounts (CPP/EI/Income Tax Payable, Net Pay Clearing, Wages & employer-cost expenses) on existing Canadian companies so pay runs can post.';

    public function handle(EnsurePayrollAccounts $ensure): int
    {
        $arg = $this->argument('company');

        $companies = $arg !== null
            ? Company::query()->withoutGlobalScopes()->where('id', $arg)->orWhere('slug', $arg)->get()
            : Company::query()->withoutGlobalScopes()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error('No matching company.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            if ($company->jurisdiction !== Country::Canada) {
                continue;
            }

            $created = $ensure->handle($company);

            $this->line(sprintf('Company %s — created %d payroll account(s).', $company->slug, $created));
        }

        return self::SUCCESS;
    }
}

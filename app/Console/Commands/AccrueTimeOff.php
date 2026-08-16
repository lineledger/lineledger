<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Payroll\TimeOffAccrualService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Grants beginning-of-year / anniversary time-off lumps and applies the
 * year-boundary carryover + YTD reset for every company's active assignments.
 * Idempotent (each cycle is granted once via the assignment's last_accrued_on),
 * so it is safe to run daily.
 */
class AccrueTimeOff extends Command
{
    protected $signature = 'payroll:accrue-time-off
        {company? : Company ID or slug; all companies when omitted}
        {--date= : Run as of this date (Y-m-d) instead of today}';

    protected $description = 'Grant beginning-of-year / anniversary time-off and roll balances over at the cycle boundary.';

    public function handle(TimeOffAccrualService $service): int
    {
        $arg = $this->argument('company');

        $companies = $arg !== null
            ? Company::query()->withoutGlobalScopes()->where('id', $arg)->orWhere('slug', $arg)->get()
            : Company::query()->withoutGlobalScopes()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error('No matching company.');

            return self::FAILURE;
        }

        $dateOption = $this->option('date');

        foreach ($companies as $company) {
            // Bind the company so BelongsToCompany stamps company_id on new balances.
            app()->instance('current_company', $company);

            $asOf = $dateOption !== null
                ? CarbonImmutable::parse($dateOption, $company->currentDateTime()->timezone)->startOfDay()
                : $company->currentDateTime()->startOfDay();

            $advanced = $service->accrueForCompany($company, $asOf);

            $this->line(sprintf('%s — %d assignment(s) accrued/rolled over.', $company->slug, $advanced));

            app()->forgetInstance('current_company');
        }

        return self::SUCCESS;
    }
}

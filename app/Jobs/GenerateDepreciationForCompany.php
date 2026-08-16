<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Assets\DepreciationGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generates all due monthly book-depreciation drafts for one company.
 * Isolated per company so a slow or erroring tenant cannot block the others.
 * "Today" is evaluated in the company's own timezone so a month is considered
 * ended on that company's calendar, not UTC's.
 */
class GenerateDepreciationForCompany implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $companyId) {}

    public function handle(DepreciationGenerator $generator): void
    {
        $company = Company::query()->findOrFail($this->companyId);

        $generator->generateDue($company, $company->currentDateTime()->startOfDay());
    }
}

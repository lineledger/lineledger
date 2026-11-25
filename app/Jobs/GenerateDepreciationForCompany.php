<?php

namespace App\Jobs;

use App\Contracts\CompanyAccess;
use App\Models\Company;
use App\Services\Assets\DepreciationGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
        $company = Company::query()->find($this->companyId);

        // Deleted between dispatch and execution: nothing to do, and not a
        // failure worth a failed_jobs row and an ops alert.
        if (! $company) {
            Log::info('Skipping depreciation drafts: company no longer exists.', ['company_id' => $this->companyId]);

            return;
        }

        // Read-only (CompanyAccess) since the command queued it: skipped, not failed.
        if (app(CompanyAccess::class)->isReadOnly($company)) {
            Log::info('Skipping depreciation drafts: the organization is read-only.', ['company_id' => $company->id]);

            return;
        }

        $generator->generateDue($company, $company->currentDateTime()->startOfDay());
    }
}

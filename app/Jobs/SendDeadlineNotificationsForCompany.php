<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Deadlines\DeadlineNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Rolls one organization's deadline occurrences forward and sends its deadline
 * emails, on its own calendar day. Isolated per company so one slow or failing
 * tenant can't hold up the rest; idempotent (see DeadlineNotifier).
 */
class SendDeadlineNotificationsForCompany implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $companyId) {}

    public function handle(DeadlineNotifier $notifier): void
    {
        $company = Company::query()->find($this->companyId);

        if (! $company) {
            Log::info('Skipping deadline notifications: company no longer exists.', ['company_id' => $this->companyId]);

            return;
        }

        $this->send($company, $notifier);
    }

    /**
     * @return array{due_soon: int, overdue: int, digest: int}
     */
    public function send(Company $company, DeadlineNotifier $notifier): array
    {
        $previous = app()->bound('current_company') ? app('current_company') : null;
        app()->instance('current_company', $company);

        try {
            return $notifier->run($company);
        } finally {
            if ($previous !== null) {
                app()->instance('current_company', $previous);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }
}

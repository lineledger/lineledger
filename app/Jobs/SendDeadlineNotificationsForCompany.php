<?php

namespace App\Jobs;

use App\Contracts\CompanyAccess;
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

    /**
     * A send that fails (the mail provider refusing a burst, a network blip)
     * is retried. Each email is claimed before it goes and a failure releases
     * its claim, so a retry sends only what didn't go out. A job that fails
     * for good loses the organization's emails until tomorrow, because the
     * command has already claimed its day.
     */
    public int $tries = 4;

    /** @var list<int> Seconds to wait before the second, third and fourth attempts. */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $companyId) {}

    public function handle(DeadlineNotifier $notifier): void
    {
        $company = Company::query()->find($this->companyId);

        if (! $company) {
            Log::info('Skipping deadline notifications: company no longer exists.', ['company_id' => $this->companyId]);

            return;
        }

        // Read-only (CompanyAccess) since the command queued it: skipped, not failed.
        if (app(CompanyAccess::class)->isReadOnly($company)) {
            Log::info('Skipping deadline notifications: the organization is read-only.', ['company_id' => $company->id]);

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

<?php

namespace App\Jobs;

use App\Actions\Sales\SendInvoiceReminder;
use App\Contracts\CompanyAccess;
use App\Enums\SensitiveFeature;
use App\Models\Company;
use App\Models\ReminderTier;
use App\Services\Reminders\DueReminderResolver;
use App\Support\Security\SensitiveFeatureGate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends every due payment reminder for one company. Isolated per company so a
 * slow or erroring tenant cannot block the others. "Today" is evaluated in the
 * company's own timezone so a tier anchored to a due date fires on that
 * company's calendar day, not UTC's. Idempotent: each (invoice, tier) is logged
 * once, so a re-run the same day sends nothing new.
 */
class SendPaymentRemindersForCompany implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $companyId) {}

    public function handle(DueReminderResolver $resolver, SendInvoiceReminder $sender): void
    {
        $company = Company::query()->find($this->companyId);

        // Deleted between dispatch and execution: nothing to do, and not a
        // failure worth a failed_jobs row and an ops alert.
        if (! $company) {
            Log::info('Skipping payment reminders: company no longer exists.', ['company_id' => $this->companyId]);

            return;
        }

        // Read-only (CompanyAccess) since the command queued it: skipped, not failed.
        if (app(CompanyAccess::class)->isReadOnly($company)) {
            Log::info('Skipping payment reminders: the organization is read-only.', ['company_id' => $company->id]);

            return;
        }

        $this->sendDue($company, $resolver, $sender);
    }

    /**
     * Send every due reminder once. Returns the number of reminders sent.
     *
     * Binds $company as the current tenant for the duration so the company-scoped
     * models written here (reminder tiers, reminder logs, portal login tokens)
     * land on the right company even when the command sweeps every tenant.
     */
    public function sendDue(Company $company, DueReminderResolver $resolver, SendInvoiceReminder $sender): int
    {
        // Customer email kept behind two-factor authentication the owner
        // hasn't turned on: nothing is sent or claimed, so once it is on, the
        // next run sends what is due then.
        if (! app(SensitiveFeatureGate::class)->allows(SensitiveFeature::CustomerEmail, $company)) {
            Log::info('Skipping payment reminders: customer email needs the owner to turn on two-factor authentication.', ['company_id' => $company->id]);

            return 0;
        }

        $previous = app()->bound('current_company') ? app('current_company') : null;
        app()->instance('current_company', $company);

        try {
            $tiers = ReminderTier::ensureDefaultsFor($company);
            $today = $company->currentDateTime()->startOfDay();

            $sent = 0;

            foreach ($resolver->due($company, $today, $tiers) as $due) {
                if ($sender->handle($company, $due['invoice'], $due['tier'])) {
                    $sent++;
                }
            }

            return $sent;
        } finally {
            if ($previous !== null) {
                app()->instance('current_company', $previous);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }
}

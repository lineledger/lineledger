<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesCompanyArgument;
use App\Console\Concerns\SkipsReadOnlyCompanies;
use App\Jobs\SendDeadlineNotificationsForCompany;
use App\Models\Company;
use App\Services\Deadlines\DeadlineNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Runs hourly. Each organization is processed once per local day, in the first
 * run at or after 07:00 in its own timezone, so deadline emails arrive in the
 * morning wherever it is. Naming a company runs it now regardless.
 */
class SendDeadlineNotifications extends Command
{
    use ResolvesCompanyArgument;
    use SkipsReadOnlyCompanies;

    /** The local hour from which an organization's daily run may start. */
    public const int SEND_FROM_HOUR = 7;

    protected $signature = 'deadlines:notify {company? : Company ID or slug; all companies when omitted} {--sync : Send inline instead of dispatching a queued job per company}';

    protected $description = 'Roll deadline occurrences forward and email due-soon and overdue reminders and digests.';

    public function handle(DeadlineNotifier $notifier): int
    {
        $arg = $this->argument('company');

        // Live companies only (Company's global scope is soft deletion).
        $companies = $arg !== null
            ? $this->whereCompanyArgument(Company::query(), $arg)->get()
            : Company::query()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error('No matching company.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            if ($this->skipsReadOnly($company)) {
                continue;
            }

            if ($arg === null && ! $this->dueToday($company)) {
                continue;
            }

            if (! $this->option('sync')) {
                SendDeadlineNotificationsForCompany::dispatch($company->id);
                $this->line(sprintf('%s — queued.', $company->slug));

                continue;
            }

            $sent = (new SendDeadlineNotificationsForCompany($company->id))->send($company, $notifier);

            $this->line(sprintf('%s — %d due-soon, %d overdue, %d digest email(s).', $company->slug, $sent['due_soon'], $sent['overdue'], $sent['digest']));
        }

        return self::SUCCESS;
    }

    /**
     * Whether the organization's run for its local today hasn't happened yet
     * and its morning has come. Claims the day when it has.
     */
    private function dueToday(Company $company): bool
    {
        $local = $company->currentDateTime();

        if ($local->hour < self::SEND_FROM_HOUR) {
            return false;
        }

        return Cache::add('deadlines:notify:'.$company->id.':'.$local->toDateString(), true, now()->addDays(2));
    }
}

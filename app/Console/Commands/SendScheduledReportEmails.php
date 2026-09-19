<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesCompanyArgument;
use App\Jobs\SendScheduledReportEmailsForCompany;
use App\Models\Company;
use App\Services\Recurring\NextRunDateCalculator;
use Illuminate\Console\Command;

class SendScheduledReportEmails extends Command
{
    use ResolvesCompanyArgument;

    protected $signature = 'reports:send-scheduled {company? : Company ID or slug; all companies when omitted} {--sync : Send inline instead of dispatching a queued job per company}';

    protected $description = 'Email memorized reports whose schedule\'s next run date has arrived.';

    public function handle(NextRunDateCalculator $calculator): int
    {
        $arg = $this->argument('company');

        // Live companies only: Company's one global scope is soft-deletion, so
        // enumerating without scopes queued work for deleted tenants that the
        // per-company job could then never load (nightly failed jobs).
        $companies = $arg !== null
            ? $this->whereCompanyArgument(Company::query(), $arg)->get()
            : Company::query()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error('No matching company.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            if (! $this->option('sync')) {
                SendScheduledReportEmailsForCompany::dispatch($company->id);
                $this->line(sprintf('%s — queued.', $company->slug));

                continue;
            }

            $sent = (new SendScheduledReportEmailsForCompany($company->id))->sendDue($company, $calculator);

            $this->line(sprintf('%s — sent %d report email(s).', $company->slug, $sent));
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\SendScheduledReportEmailsForCompany;
use App\Models\Company;
use App\Services\Recurring\NextRunDateCalculator;
use Illuminate\Console\Command;

class SendScheduledReportEmails extends Command
{
    protected $signature = 'reports:send-scheduled {company? : Company ID or slug; all companies when omitted} {--sync : Send inline instead of dispatching a queued job per company}';

    protected $description = 'Email memorized reports whose schedule\'s next run date has arrived.';

    public function handle(NextRunDateCalculator $calculator): int
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

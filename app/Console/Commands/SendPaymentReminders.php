<?php

namespace App\Console\Commands;

use App\Actions\Sales\SendInvoiceReminder;
use App\Console\Concerns\ResolvesCompanyArgument;
use App\Jobs\SendPaymentRemindersForCompany;
use App\Models\Company;
use App\Services\Reminders\DueReminderResolver;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    use ResolvesCompanyArgument;

    protected $signature = 'reminders:send {company? : Company ID or slug; all companies when omitted} {--sync : Send inline instead of dispatching a queued job per company}';

    protected $description = 'Email automated payment reminders for invoices whose due date has reached a reminder tier.';

    public function handle(DueReminderResolver $resolver, SendInvoiceReminder $sender): int
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
                SendPaymentRemindersForCompany::dispatch($company->id);
                $this->line(sprintf('%s — queued.', $company->slug));

                continue;
            }

            $sent = (new SendPaymentRemindersForCompany($company->id))->sendDue($company, $resolver, $sender);

            $this->line(sprintf('%s — sent %d reminder(s).', $company->slug, $sent));
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Actions\Sales\SendInvoiceReminder;
use App\Jobs\SendPaymentRemindersForCompany;
use App\Models\Company;
use App\Services\Reminders\DueReminderResolver;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    protected $signature = 'reminders:send {company? : Company ID or slug; all companies when omitted} {--sync : Send inline instead of dispatching a queued job per company}';

    protected $description = 'Email automated payment reminders for invoices whose due date has reached a reminder tier.';

    public function handle(DueReminderResolver $resolver, SendInvoiceReminder $sender): int
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

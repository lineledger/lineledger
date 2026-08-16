<?php

namespace App\Console\Commands;

use App\Jobs\GenerateRecurringDocumentsForCompany;
use App\Models\Company;
use App\Models\RecurringDocument;
use App\Models\RecurringJournalEntry;
use App\Services\Recurring\RecurringDocumentGenerator;
use App\Services\Recurring\RecurringJournalEntryGenerator;
use Illuminate\Console\Command;

class GenerateRecurringDocuments extends Command
{
    protected $signature = 'recurring:generate {company? : Company ID or slug; all companies when omitted} {--sync : Generate inline instead of dispatching a queued job per company}';

    protected $description = 'Generate Draft invoices, bills, and journal entries from recurring schedules whose next run date has arrived.';

    public function handle(RecurringDocumentGenerator $generator, RecurringJournalEntryGenerator $journalGenerator): int
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
                GenerateRecurringDocumentsForCompany::dispatch($company->id);
                $this->line(sprintf('%s — queued.', $company->slug));

                continue;
            }

            $today = $company->currentDateTime()->startOfDay();

            $count = 0;
            RecurringDocument::query()
                ->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->due($today->toDateString())
                ->orderBy('id')
                ->each(function (RecurringDocument $document) use ($generator, $today, &$count): void {
                    $count += $generator->generateDue($document, $today)->count();
                });

            RecurringJournalEntry::query()
                ->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->due($today->toDateString())
                ->orderBy('id')
                ->each(function (RecurringJournalEntry $schedule) use ($journalGenerator, $today, &$count): void {
                    $count += $journalGenerator->generateDue($schedule, $today)->count();
                });

            $this->line(sprintf('%s — generated %d draft(s).', $company->slug, $count));
        }

        return self::SUCCESS;
    }
}

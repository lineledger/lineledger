<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\RecurringDocument;
use App\Models\RecurringJournalEntry;
use App\Services\Recurring\RecurringDocumentGenerator;
use App\Services\Recurring\RecurringJournalEntryGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generates all due recurring documents and journal entries for one company.
 * Isolated per company so a slow or erroring tenant cannot block the others.
 * "Today" is evaluated in the company's own timezone so a schedule anchored to a
 * calendar day fires on that company's day, not UTC's.
 */
class GenerateRecurringDocumentsForCompany implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $companyId) {}

    public function handle(RecurringDocumentGenerator $generator, RecurringJournalEntryGenerator $journalGenerator): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        $today = $company->currentDateTime()->startOfDay();

        RecurringDocument::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->due($today->toDateString())
            ->orderBy('id')
            ->each(function (RecurringDocument $document) use ($generator, $today): void {
                $generator->generateDue($document, $today);
            });

        RecurringJournalEntry::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->due($today->toDateString())
            ->orderBy('id')
            ->each(function (RecurringJournalEntry $schedule) use ($journalGenerator, $today): void {
                $journalGenerator->generateDue($schedule, $today);
            });
    }
}

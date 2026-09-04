<?php

namespace App\Actions\Banking;

use App\Enums\StatementLineMatchStatus;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Services\Banking\Import\StatementImportCommitter;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Posts a single bank statement line to the general ledger against a chosen
 * contra account — the "Add" action from a feed. Builds the balanced two-leg
 * entry by the line's sign (money in debits the bank; money out credits it),
 * posts it through the audited pipeline, and stamps the line so it reads as
 * Created and links back to its entry.
 *
 * Extracted from {@see StatementImportCommitter}. Callers should go through
 * {@see RecordStatementLine}, which decides between this plain entry, an
 * Expense to a vendor, or a bill payment; this is the journal-entry primitive.
 */
final class AddStatementLineEntry
{
    public function __construct(
        private readonly JournalPoster $poster,
        private readonly EntryNumberGenerator $entryNumbers,
    ) {}

    public function handle(BankStatementLine $line, int $contraAccountId, ?int $contactId = null): JournalEntry
    {
        return DB::transaction(function () use ($line, $contraAccountId, $contactId): JournalEntry {
            $account = $line->account()->firstOrFail();
            $amount = abs((int) $line->amount_cents);
            $memo = ($line->description !== null && $line->description !== '') ? $line->description : null;

            $entry = JournalEntry::query()->create([
                'company_id' => $account->company_id,
                'entry_no' => $this->entryNumbers->next($account->company),
                'entry_date' => CarbonImmutable::parse($line->txn_date)->toDateString(),
                'memo' => $memo ?? __('Bank statement import'),
                'source_type' => BankStatementImport::class,
                'source_id' => $line->bank_statement_import_id,
            ]);

            $bank = ['account_id' => $account->id, 'memo' => $memo, 'line_order' => 0];
            $contra = ['account_id' => $contraAccountId, 'contact_id' => $contactId, 'memo' => $memo, 'line_order' => 1];

            if ((int) $line->amount_cents >= 0) {
                // Money in: DR bank, CR the chosen income/contra account.
                $bankLine = $entry->lines()->create($bank + ['debit_cents' => $amount, 'credit_cents' => 0]);
                $entry->lines()->create($contra + ['debit_cents' => 0, 'credit_cents' => $amount]);
            } else {
                // Money out: DR the chosen expense/contra account, CR bank.
                $entry->lines()->create($contra + ['debit_cents' => $amount, 'credit_cents' => 0]);
                $bankLine = $entry->lines()->create($bank + ['debit_cents' => 0, 'credit_cents' => $amount]);
            }

            $entry->refresh();
            $this->poster->post($entry);

            $line->forceFill([
                'suggested_account_id' => $contraAccountId,
                'created_journal_entry_id' => $entry->id,
                'matched_journal_line_id' => $bankLine->id,
                'match_status' => StatementLineMatchStatus::Created->value,
            ])->save();

            return $entry;
        });
    }
}

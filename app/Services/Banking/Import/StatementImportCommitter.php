<?php

namespace App\Services\Banking\Import;

use App\Actions\Banking\AddStatementLineEntry;
use App\Enums\BankStatementImportStatus;
use App\Enums\StatementLineMatchStatus;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Services\Reconciliation\BankReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a reviewed statement import into a pre-filled, in-progress bank
 * reconciliation. It creates real journal entries for the lines the user chose to
 * "Add", collects those plus every confirmed match, and ticks them all on a
 * reconciliation (reusing one if already open). It deliberately stops at an
 * in-progress rec so the existing, audited complete() path does the actual clearing.
 *
 * Created "Add" entries are real, posted transactions linked to the import for
 * traceability; like QuickBooks' "add from feed", they persist even if the user
 * later cancels the reconciliation.
 */
class StatementImportCommitter
{
    public function __construct(
        private readonly BankReconciliationService $reconciliations,
        private readonly AddStatementLineEntry $addLineEntry,
    ) {}

    public function commit(BankStatementImport $import, ?int $userId = null): BankReconciliation
    {
        if ($import->isCommitted()) {
            throw new RuntimeException('This statement has already been committed.');
        }

        /** @var Account $account */
        $account = $import->account()->firstOrFail();

        return DB::transaction(function () use ($import, $account) {
            $markedIds = [];
            $createdCount = 0;

            $lines = $import->lines()->orderBy('txn_date')->orderBy('id')->get();

            foreach ($lines as $line) {
                $status = $line->match_status;

                if ($status === StatementLineMatchStatus::Matched && $line->matched_journal_line_id !== null) {
                    $markedIds[] = (int) $line->matched_journal_line_id;
                } elseif ($status === StatementLineMatchStatus::Created) {
                    $markedIds[] = $this->createEntryForLine($line);
                    $createdCount++;
                }
            }

            $rec = $this->openReconciliation($account, $import);

            $this->reconciliations->markLines($rec, $markedIds);

            $this->attachStatementFile($import, $rec);

            $import->forceFill([
                'bank_reconciliation_id' => $rec->id,
                'created_count' => $createdCount,
                'status' => BankStatementImportStatus::Committed->value,
            ])->save();

            return $rec->fresh();
        });
    }

    /**
     * Post a new journal entry for an "Add" line via the shared action and return
     * its bank-side line id (used to tick the reconciliation).
     */
    private function createEntryForLine(BankStatementLine $line): int
    {
        if ($line->suggested_account_id === null) {
            throw new RuntimeException("Choose a category for the added line dated {$line->txn_date?->toDateString()} before committing.");
        }

        $this->addLineEntry->handle($line, (int) $line->suggested_account_id);

        return (int) $line->matched_journal_line_id;
    }

    /**
     * Reuse an in-progress reconciliation for the account, or open a fresh one dated
     * to the statement's close, seeding the ending balance from the file when known.
     */
    private function openReconciliation(Account $account, BankStatementImport $import): BankReconciliation
    {
        $existing = BankReconciliation::query()
            ->forAccount($account->id)
            ->inProgress()
            ->first();

        if ($existing) {
            return $existing;
        }

        $statementDate = $import->statement_end_date
            ? CarbonImmutable::parse($import->statement_end_date)
            : $account->company->currentDateTime();

        return $this->reconciliations->begin(
            $account,
            $statementDate,
            (int) ($import->statement_end_balance_cents ?? 0),
        );
    }

    /**
     * Re-point the uploaded statement file at the reconciliation so it shows in the
     * rec's "Statement & documents" panel and is purged with it.
     */
    private function attachStatementFile(BankStatementImport $import, BankReconciliation $rec): void
    {
        if ($import->attachment_id === null) {
            return;
        }

        Attachment::query()
            ->whereKey($import->attachment_id)
            ->update([
                'attachable_type' => $rec->getMorphClass(),
                'attachable_id' => $rec->id,
            ]);
    }
}

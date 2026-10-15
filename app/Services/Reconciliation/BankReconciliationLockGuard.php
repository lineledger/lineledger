<?php

namespace App\Services\Reconciliation;

use App\Enums\BankReconciliationStatus;
use App\Exceptions\Posting\ReconciliationLockedException;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Blocks changing or voiding a transaction that contains a journal line a
 * completed bank reconciliation has claimed (journal_lines.bank_reconciliation_id).
 * That reconciliation's cleared balance is the beginning balance plus the lines
 * it ticked, so rewriting, re-dating or reversing one of them would silently
 * break it; the user must undo the reconciliation first.
 *
 * New postings dated inside a reconciled period are deliberately NOT blocked:
 * an unticked line changes no completed reconciliation and simply appears as an
 * uncleared item on the account's next one (e.g. an accountant's year-end
 * adjusting entry dated after the bank was reconciled past it).
 *
 * The reconciliation service's own service-charge / interest entries are exempt via
 * ReconciliationLockBypass while it reverses a completed reconciliation.
 */
class BankReconciliationLockGuard
{
    /**
     * @param  iterable<JournalLine|int|null>  $lines  The transaction's journal lines (or their IDs; nulls are ignored).
     */
    public function ensureLinesNotReconciled(int $companyId, iterable $lines): void
    {
        if (ReconciliationLockBypass::isActive()) {
            return;
        }

        $ids = collect($lines)
            ->map(fn ($line) => $line instanceof JournalLine ? $line->id : $line)
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $row = DB::table('journal_lines')
            ->join('bank_reconciliations', 'bank_reconciliations.id', '=', 'journal_lines.bank_reconciliation_id')
            ->join('accounts', 'accounts.id', '=', 'bank_reconciliations.account_id')
            ->where('bank_reconciliations.company_id', $companyId)
            ->where('bank_reconciliations.status', BankReconciliationStatus::Completed->value)
            ->whereIn('journal_lines.id', $ids)
            ->orderByDesc('bank_reconciliations.statement_date')
            ->select(['accounts.name as account_name', 'bank_reconciliations.statement_date'])
            ->first();

        if ($row === null) {
            return;
        }

        throw ReconciliationLockedException::for(
            (string) $row->account_name,
            CarbonImmutable::parse($row->statement_date),
        );
    }
}

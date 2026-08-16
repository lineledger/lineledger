<?php

namespace App\Services\Reconciliation;

use App\Enums\BankReconciliationStatus;
use App\Exceptions\Posting\ReconciliationLockedException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Blocks posting (or voiding) of a transaction that touches a bank account with a
 * completed reconciliation covering the entry date. Once an account is reconciled
 * through a statement date, back-dated edits into that period would silently break
 * the reconciled balance, so they are refused until the reconciliation is undone.
 *
 * The reconciliation service's own service-charge / interest entries are exempt via
 * ReconciliationLockBypass while it reverses a completed reconciliation.
 */
class BankReconciliationLockGuard
{
    /**
     * @param  iterable<int|null>  $accountIds  Account IDs touched by the transaction (nulls are ignored).
     */
    public function ensureNotReconciled(int $companyId, iterable $accountIds, CarbonInterface $entryDate): void
    {
        if (ReconciliationLockBypass::isActive()) {
            return;
        }

        $ids = collect($accountIds)
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $row = DB::table('bank_reconciliations')
            ->join('accounts', 'accounts.id', '=', 'bank_reconciliations.account_id')
            ->where('bank_reconciliations.company_id', $companyId)
            ->where('bank_reconciliations.status', BankReconciliationStatus::Completed->value)
            ->whereIn('bank_reconciliations.account_id', $ids)
            ->whereDate('bank_reconciliations.statement_date', '>=', $entryDate)
            ->orderByDesc('bank_reconciliations.statement_date')
            ->select(['accounts.name as account_name', 'bank_reconciliations.statement_date'])
            ->first();

        if ($row === null) {
            return;
        }

        throw ReconciliationLockedException::for(
            (string) $row->account_name,
            CarbonImmutable::parse($entryDate),
            CarbonImmutable::parse($row->statement_date),
        );
    }
}

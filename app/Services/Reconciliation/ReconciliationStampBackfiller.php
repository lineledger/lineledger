<?php

namespace App\Services\Reconciliation;

use App\Models\BankReconciliation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Audit\PostedMutationGate;
use Illuminate\Support\Facades\DB;

/**
 * Takes a completed reconciliation's cleared stamp back off the service-charge
 * and interest lines it never counted.
 *
 * Those lines are stamped with their reconciliation the moment they are posted.
 * Until the fix, replacing one mid-edit (or leaving it unticked) kept the stamp,
 * so after completion the register counted a charge the reconciliation had not:
 * the register's cleared balance drifted from the reconciled one, and the
 * replaced charge's reversal came back as a phantom item on the next
 * reconciliation. Completing a reconciliation now removes those stamps; this
 * catches everything completed before.
 *
 * It is deliberately narrow. A line is only un-stamped when it sits on an
 * adjustment entry that very reconciliation posted and is missing from the
 * lines the reconciliation ticked. A reconciliation whose ticked lines do not
 * line up with its stamped ones (a backup restore renumbers lines but not the
 * ticked list) is skipped and reported rather than guessed at. Amounts are never
 * touched. Idempotent — a second run matches nothing.
 */
class ReconciliationStampBackfiller
{
    /**
     * @return array{unstamped: int, reconciliations: int, skipped: list<int>}
     */
    public function backfill(int $companyId, bool $dryRun = false): array
    {
        $unstamped = 0;
        $repaired = 0;
        $skipped = [];

        $recs = BankReconciliation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->completed()
            ->orderBy('id')
            ->get();

        foreach ($recs as $rec) {
            $marked = $rec->markedLineIds();

            $stamped = JournalLine::query()
                ->where('bank_reconciliation_id', $rec->id)
                ->get(['id', 'journal_entry_id']);

            $stale = $stamped->reject(fn (JournalLine $line) => in_array((int) $line->id, $marked, true));

            if ($stale->isEmpty()) {
                continue;
            }

            if (! $this->isTrustworthy($rec, $marked, $stamped->pluck('id')->map(fn ($id) => (int) $id)->all(), $stale->pluck('journal_entry_id')->all())) {
                $skipped[] = (int) $rec->id;

                continue;
            }

            $ids = $stale->pluck('id')->map(fn ($id) => (int) $id)->all();

            if (! $dryRun) {
                PostedMutationGate::within(fn () => DB::transaction(fn () => JournalLine::query()
                    ->whereIn('id', $ids)
                    ->where('bank_reconciliation_id', $rec->id)
                    ->update(['cleared_at' => null, 'bank_reconciliation_id' => null])));
            }

            $unstamped += count($ids);
            $repaired++;
        }

        return ['unstamped' => $unstamped, 'reconciliations' => $repaired, 'skipped' => $skipped];
    }

    /**
     * Completing a reconciliation stamps every line it ticked, so a trustworthy
     * one has each ticked line among its stamped lines — and every stamped line
     * it did not tick is an adjustment it posted itself.
     *
     * @param  list<int>  $marked
     * @param  list<int>  $stampedIds
     * @param  list<int|string>  $staleEntryIds
     */
    private function isTrustworthy(BankReconciliation $rec, array $marked, array $stampedIds, array $staleEntryIds): bool
    {
        if (array_diff($marked, $stampedIds) !== []) {
            return false;
        }

        $entryIds = array_values(array_unique(array_map('intval', $staleEntryIds)));

        $ownAdjustments = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $rec->company_id)
            ->whereIn('id', $entryIds)
            ->where('source_type', BankReconciliation::class)
            ->where('source_id', $rec->id)
            ->count();

        return $ownAdjustments === count($entryIds);
    }
}

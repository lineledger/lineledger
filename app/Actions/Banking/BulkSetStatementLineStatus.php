<?php

namespace App\Actions\Banking;

use App\Enums\StatementLineMatchStatus;
use App\Models\BankStatementLine;

/**
 * Bulk exclude / re-include reviewable bank lines. Pure status updates with no
 * ledger effect: excluding moves reviewable lines to Ignored; including brings
 * Ignored (never-posted) lines back to Unmatched. Both stay within the current
 * company via the global scope.
 */
final class BulkSetStatementLineStatus
{
    /**
     * @param  array<int, int|string>  $lineIds
     * @return int Rows excluded.
     */
    public function exclude(array $lineIds): int
    {
        $ids = $this->ids($lineIds);

        if ($ids === []) {
            return 0;
        }

        return BankStatementLine::query()
            ->forReview()
            ->whereIn('id', $ids)
            ->update(['match_status' => StatementLineMatchStatus::Ignored->value]);
    }

    /**
     * @param  array<int, int|string>  $lineIds
     * @return int Rows re-included.
     */
    public function include(array $lineIds): int
    {
        $ids = $this->ids($lineIds);

        if ($ids === []) {
            return 0;
        }

        return BankStatementLine::query()
            ->where('match_status', StatementLineMatchStatus::Ignored->value)
            ->whereNull('created_journal_entry_id')
            ->whereIn('id', $ids)
            ->update(['match_status' => StatementLineMatchStatus::Unmatched->value]);
    }

    /**
     * @param  array<int, int|string>  $lineIds
     * @return list<int>
     */
    private function ids(array $lineIds): array
    {
        return array_values(array_unique(array_map('intval', $lineIds)));
    }
}

<?php

namespace App\Actions\Banking;

use App\Models\BankStatementLine;
use Illuminate\Support\Facades\DB;

/**
 * Posts several reviewable bank lines to one category (and, optionally, one
 * contact — outflows then become expenses to that vendor) in a single
 * all-or-nothing transaction: if any line fails (e.g. it falls in a locked
 * period), the whole batch rolls back and nothing posts. Each line is
 * re-checked as still reviewable and locked, so a line accepted in another tab
 * is skipped rather than double-posted.
 */
final class BulkCategorizeStatementLines
{
    /** Cap a synchronous batch so the request can't time out. */
    public const MAX = 200;

    public function __construct(private readonly RecordStatementLine $recordLine) {}

    /**
     * @param  array<int, int|string>  $lineIds
     * @return int Number of lines posted.
     */
    public function handle(array $lineIds, int $contraAccountId, ?int $contactId = null): int
    {
        $ids = array_slice(array_values(array_unique(array_map('intval', $lineIds))), 0, self::MAX);

        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($ids, $contraAccountId, $contactId): int {
            $count = 0;

            foreach ($ids as $id) {
                $line = BankStatementLine::query()->forReview()->whereKey($id)->lockForUpdate()->first();

                if ($line === null) {
                    continue;
                }

                $this->recordLine->handle($line, $contraAccountId, $contactId);
                $count++;
            }

            return $count;
        });
    }
}

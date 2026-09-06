<?php

namespace App\Services\Audit;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Escape hatch for the `journal_entries`/`journal_lines` DB triggers (see
 * migration `..._add_posted_ledger_immutability_triggers`) that reject any
 * UPDATE/DELETE on a row once its entry is posted. That's defense-in-depth
 * against anything below the Eloquent layer (Finding #5, 2026-08-18 security
 * review) — a compromised app/queue container, an operator with raw DB
 * credentials, or a future SQL-injection primitive can't tamper with posted
 * financial data directly, because none of those know to set the session
 * variable this gate sets.
 *
 * Only the few reviewed, audit-logged code paths that legitimately touch an
 * already-posted row in place need to call through here:
 * {@see \App\Actions\Accounting\SaveJournalEntry} (repost-in-place) and
 * {@see \App\Services\Posting\JournalPoster::void()}.
 */
final class PostedMutationGate
{
    /**
     * Reentrancy depth: some gated methods call other gated methods (e.g.
     * {@see \App\Services\Posting\JournalPoster::void()} calls {@see
     * \App\Services\Posting\JournalPoster::post()} to post the reversal). Only
     * the outermost call may clear the session variable — otherwise the inner
     * call's `finally` would reset it to NULL while the outer call still needs
     * it set, letting the trigger reject the outer call's own posted-row update.
     */
    private static int $depth = 0;

    public static function within(Closure $callback): mixed
    {
        if (DB::getDriverName() !== 'mysql') {
            return $callback();
        }

        if (self::$depth === 0) {
            DB::statement('SET @ll_allow_posted_mutation = 1');
        }
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
            if (self::$depth === 0) {
                DB::statement('SET @ll_allow_posted_mutation = NULL');
            }
        }
    }
}

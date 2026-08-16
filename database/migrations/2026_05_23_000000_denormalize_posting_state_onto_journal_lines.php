<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Computing an account balance "as of" a date is the single hottest query in
     * the app (dashboard, every report, the bank register). It sums debit − credit
     * over a line set filtered by the parent entry's is_posted + entry_date — which
     * forced a join/EXISTS against journal_entries on every line. At decades of
     * history that is the dominant cost.
     *
     * Denormalising the two posting attributes onto journal_lines turns the balance
     * query into a single-table aggregate that a covering index can answer without
     * touching the row or the journal_entries table. The columns are kept in sync by
     * model events on JournalEntry/JournalLine (see those models).
     */
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->boolean('is_posted')->default(false)->after('account_id');
            $table->date('entry_date')->nullable()->after('is_posted');
        });

        $this->backfillFromEntries();

        Schema::table('journal_lines', function (Blueprint $table) {
            // Covering index for the balance-as-of-date aggregate:
            // WHERE account_id = ? AND is_posted = 1 AND entry_date <= ?
            // SUM(debit_cents - credit_cents). Trailing amount columns let MySQL
            // answer the sum from the index alone (no clustered-index lookups).
            $table->index(
                ['account_id', 'is_posted', 'entry_date', 'debit_cents', 'credit_cents'],
                'journal_lines_balance_idx',
            );
        });
    }

    /**
     * Copy is_posted / entry_date from each line's parent entry. Chunked by id so a
     * decades-deep table never locks in one statement. The correlated subquery form
     * works identically on MySQL and SQLite.
     */
    private function backfillFromEntries(): void
    {
        $lastId = 0;

        do {
            $ids = DB::table('journal_lines')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(5000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            DB::table('journal_lines')
                ->whereIn('id', $ids)
                ->update([
                    'is_posted' => DB::raw('(SELECT je.is_posted FROM journal_entries je WHERE je.id = journal_lines.journal_entry_id)'),
                    'entry_date' => DB::raw('(SELECT je.entry_date FROM journal_entries je WHERE je.id = journal_lines.journal_entry_id)'),
                ]);

            $lastId = $ids->last();
        } while (true);
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex('journal_lines_balance_idx');
            $table->dropColumn(['is_posted', 'entry_date']);
        });
    }
};

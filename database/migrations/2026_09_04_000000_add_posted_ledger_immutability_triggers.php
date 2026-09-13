<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Services\Audit\PostedMutationGate;
use App\Services\Posting\JournalPoster;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finding #5 (2026-08-18 security review): the hash-chained audit log and its
 * DB triggers protect `accounting_audit_logs`, not the live ledger tables
 * themselves — enforcement that a posted `journal_entries`/`journal_lines` row
 * can't be edited or deleted was 100% application-layer (Eloquent guards,
 * controller checks, JournalPoster). Anything below Eloquent — a compromised
 * app/queue container, an operator with raw DB credentials, a future
 * SQL-injection primitive — could tamper with posted financial data directly,
 * bypassing the audit trail entirely (no row written, hash chain silent).
 *
 * Mirrors the accounting_audit_logs trigger pattern, but gated by a session
 * variable ({@see PostedMutationGate}) rather than an
 * outright block: two reviewed, audit-logged application flows legitimately
 * mutate an already-posted row in place — repost-in-place
 * ({@see SaveJournalEntry}, which updates the entry's
 * header and hard-deletes+recreates its lines) and void
 * ({@see JournalPoster::void()}, which stamps
 * voided_at/reversed_by_entry_id on the original entry). Only code that
 * explicitly opts in via the gate can bypass the trigger; a raw connection
 * that doesn't know to set `@ll_allow_posted_mutation` cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_entries_no_update_when_posted
            BEFORE UPDATE ON journal_entries
            FOR EACH ROW
            BEGIN
                IF OLD.is_posted = 1 AND @ll_allow_posted_mutation IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Posted journal entries are immutable at the database layer; void or repost through the app instead.';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_entries_no_delete_when_posted
            BEFORE DELETE ON journal_entries
            FOR EACH ROW
            BEGIN
                IF OLD.is_posted = 1 AND @ll_allow_posted_mutation IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Posted journal entries are immutable at the database layer; void instead of deleting.';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_lines_no_update_when_posted
            BEFORE UPDATE ON journal_lines
            FOR EACH ROW
            BEGIN
                IF OLD.is_posted = 1 AND @ll_allow_posted_mutation IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Posted journal lines are immutable at the database layer; void or repost through the app instead.';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_lines_no_delete_when_posted
            BEFORE DELETE ON journal_lines
            FOR EACH ROW
            BEGIN
                IF OLD.is_posted = 1 AND @ll_allow_posted_mutation IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Posted journal lines are immutable at the database layer; void or repost through the app instead.';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_no_update_when_posted');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_no_delete_when_posted');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_no_update_when_posted');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_no_delete_when_posted');
    }
};

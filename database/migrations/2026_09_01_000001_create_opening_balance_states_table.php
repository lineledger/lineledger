<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The anchor row for the post-setup Opening Balances workspace: one per
     * company, holding the as-of (conversion) date and a pointer to the
     * maintained opening journal entry the synchronizer keeps in step with the
     * draft trial balance. Deliberately NOT data_migration_runs — that table is
     * one-shot (finalize locks the books) and auto-created by the QB wizard,
     * while this workspace stays continuously editable.
     */
    public function up(): void
    {
        Schema::create('opening_balance_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('as_of_date');
            $table->string('status', 20)->default('active'); // active | finalized
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            // Why the last auto-apply could not post (period lock / reconciliation
            // lock); null when the ledger matches the draft.
            $table->string('apply_error', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_states');
    }
};

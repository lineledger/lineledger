<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A period-end Home Currency Adjustment run. Revalues open foreign monetary
     * balances (foreign AR/AP control, foreign bank/credit) at the closing rate
     * and posts one balanced journal entry to Unrealized Gain/Loss, plus an
     * auto-reversing entry dated the next day. Stored so runs are idempotent and
     * listable; rate_snapshot records the closing rate used per currency.
     */
    public function up(): void
    {
        Schema::create('currency_revaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('as_of_date');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('rate_snapshot');
            $table->timestamps();

            $table->index(['company_id', 'as_of_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_revaluations');
    }
};

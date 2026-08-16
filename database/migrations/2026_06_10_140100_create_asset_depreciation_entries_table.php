<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per asset per depreciation month — the idempotency ledger for
        // the nightly book-depreciation generator. The journal_entry_id cascade
        // means deleting a generated draft frees its months for regeneration,
        // while voiding a posted entry leaves the rows (voided months are done).
        Schema::create('asset_depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->date('period'); // first day of the depreciation month
            $table->bigInteger('amount_cents');
            $table->timestamps();

            $table->unique(['asset_id', 'period']);
            $table->index(['company_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_entries');
    }
};

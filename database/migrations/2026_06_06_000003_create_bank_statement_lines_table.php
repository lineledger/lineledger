<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One normalized transaction from a statement file. amount_cents is a signed
        // book-delta: positive = a debit to the account (money into an asset bank /
        // payment to a credit card), negative = a credit. This matches OFX TRNAMT and
        // the ledger's (debit_cents - credit_cents), so matching is a signed compare.
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_statement_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();

            $table->date('txn_date');
            $table->bigInteger('amount_cents');
            $table->text('description')->nullable();
            $table->string('check_number')->nullable();
            $table->string('reference')->nullable();

            // OFX FITID when present — unique per account, the basis for exact dedup.
            $table->string('external_id')->nullable();
            // hash(account|date|amount|normalized desc|external_id) — idempotent re-import.
            $table->string('fingerprint');
            $table->bigInteger('balance_cents')->nullable();
            $table->json('raw')->nullable();

            $table->string('match_status')->default('unmatched');
            $table->unsignedTinyInteger('match_confidence')->nullable();
            $table->string('match_reason')->nullable();
            $table->foreignId('matched_journal_line_id')->nullable()->constrained('journal_lines')->nullOnDelete();
            $table->foreignId('created_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('suggested_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->timestamps();

            $table->index(['bank_statement_import_id', 'fingerprint']);
            $table->index(['account_id', 'external_id']);
            $table->index(['account_id', 'txn_date', 'amount_cents']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};

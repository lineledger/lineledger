<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of each deleted transaction (App\Models\DeletedTransaction): the
 * Voided/Deleted Transactions report and the deleted transaction's page read
 * it, and auto-numbering skips the numbers it holds. The hash-chained audit
 * row about the delete names it as its auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deleted_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 40);
            // The deleted row's own id, kept as history: no foreign key, never remapped.
            $table->unsignedBigInteger('original_id');
            $table->string('document_table', 64);
            $table->string('document_number', 100)->nullable();
            // Cheque-number reservations are per bank account.
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            // The highest JE-nnnnnn sequence among the deleted journal entries.
            $table->unsignedBigInteger('max_entry_seq')->nullable();
            $table->date('document_date')->nullable();
            $table->string('contact_name')->nullable();
            $table->bigInteger('total_cents')->default(0);
            $table->string('currency_code', 3)->nullable();
            $table->string('state_at_deletion', 20);
            $table->text('reason')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deleted_via', 20)->default('ui');
            $table->json('snapshot');
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'document_table', 'document_number'], 'deleted_transactions_number_index');
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'document_type', 'original_id'], 'deleted_transactions_original_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_transactions');
    }
};

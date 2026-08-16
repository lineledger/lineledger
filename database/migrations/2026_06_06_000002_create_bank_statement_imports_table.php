<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One uploaded bank statement file, tracked from upload through parse,
        // match, review and commit. Links to the BankReconciliation it pre-fills.
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('bank_reconciliation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_import_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained()->nullOnDelete();

            $table->string('source_format');
            $table->string('original_filename')->nullable();
            $table->string('status')->default('uploaded');

            $table->date('statement_begin_date')->nullable();
            $table->date('statement_end_date')->nullable();
            $table->bigInteger('statement_begin_balance_cents')->nullable();
            $table->bigInteger('statement_end_balance_cents')->nullable();

            // The column mapping used (CSV/Excel) and free-form parser diagnostics
            // (detected delimiter, date format, sign convention, ai_used, confidence).
            $table->json('mapping')->nullable();
            $table->json('parse_meta')->nullable();

            $table->unsignedInteger('line_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);

            $table->text('error_message')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_imports');
    }
};

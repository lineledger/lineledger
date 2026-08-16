<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_cheques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_run_id')->constrained('pay_runs')->cascadeOnDelete();
            $table->foreignId('pay_run_line_id')->unique()->constrained('pay_run_lines')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('cheque_no', 40);
            $table->date('cheque_date');
            $table->foreignId('payee_contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('payee_name');
            $table->bigInteger('amount_cents')->default(0);
            $table->string('status', 20)->default('draft'); // draft|posted|void
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'bank_account_id', 'cheque_no'], 'payroll_cheques_no_unique');
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_cheques');
    }
};

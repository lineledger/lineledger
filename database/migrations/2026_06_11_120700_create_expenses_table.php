<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // The money source: a bank (asset) OR credit-card (liability) account.
            $table->foreignId('payment_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('reference', 40)->nullable(); // confirmation / cheque number, optional
            $table->date('expense_date');
            $table->foreignId('payee_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('payee_name'); // free-text fallback or override
            $table->text('memo')->nullable();
            $table->bigInteger('amount_cents')->default(0);
            $table->char('currency_code', 3)->nullable();
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->bigInteger('home_amount_cents')->nullable();
            $table->string('status', 20)->default('draft'); // draft|posted|void
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'expense_date']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'payment_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};

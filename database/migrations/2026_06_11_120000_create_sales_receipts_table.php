<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pay-now sale (QuickBooks "Sales Receipt"): books revenue + tax and takes
     * the cash in one posted entry, with no Accounts Receivable. The cash debit
     * lands in `deposit_to_account_id` (Undeposited Funds or a bank). The contact
     * is optional, matching QBO (a walk-in cash sale needs no customer name).
     *
     * Currency columns mirror the other documents: for a foreign receipt the
     * *_cents columns hold the foreign amount, fx_rate is locked at posting, and
     * home_total_cents caches the home value.
     */
    public function up(): void
    {
        Schema::create('sales_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sales_receipt_no', 40);
            $table->date('receipt_date');
            $table->foreignId('deposit_to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('status', 20)->default('draft'); // draft|posted|void
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->char('currency_code', 3)->nullable();
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->bigInteger('home_total_cents')->nullable();
            $table->text('memo')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sales_receipt_no']);
            $table->index(['company_id', 'contact_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'receipt_date']);
            $table->index(['company_id', 'deposit_to_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_receipts');
    }
};

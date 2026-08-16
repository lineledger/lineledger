<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AP-side credit document — the mirror of a customer credit memo. Posts
     * DR Accounts Payable / CR expense(+recoverable tax), reducing what the company
     * owes the vendor. GL-netted: the AP aging and bill reconciler pick it up via the
     * AP control account; there is no per-bill application table.
     */
    public function up(): void
    {
        Schema::create('vendor_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('vendor_credit_no', 40);
            $table->date('vendor_credit_date');
            $table->string('status', 20)->default('draft'); // draft|posted|void
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->char('currency_code', 3)->nullable();
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->bigInteger('home_total_cents')->nullable();
            $table->text('memo')->nullable();
            $table->text('vendor_message')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'vendor_credit_no']);
            $table->index(['company_id', 'contact_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'vendor_credit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_credits');
    }
};

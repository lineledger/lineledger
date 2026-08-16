<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recorded donation — the money side of a gift (the GL transaction), distinct
     * from the official CRA receipt (the tax artifact). Posting books DR deposit /
     * CR donation revenue (unrestricted) or CR the deferred/restricted liability.
     */
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('donation_no', 40);
            $table->string('status', 20)->default('draft');
            $table->string('gift_type', 20)->default('cash');
            $table->date('donation_date');
            $table->bigInteger('amount_cents')->default(0);
            $table->string('currency_code', 3)->nullable();
            $table->boolean('is_restricted')->default(false);
            $table->foreignId('fund_id')->nullable()->constrained('funds')->nullOnDelete();
            $table->text('restriction_note')->nullable();
            $table->foreignId('deposit_to_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('deferred_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('donation_receipt_id')->nullable()->constrained('donation_receipts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'donation_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'donation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};

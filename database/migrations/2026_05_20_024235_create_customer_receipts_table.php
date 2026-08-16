<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('receipt_no', 40);
            $table->date('receipt_date');
            $table->foreignId('deposit_to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('payment_method', 40)->nullable();
            $table->string('reference', 100)->nullable();
            $table->bigInteger('amount_cents')->default(0);
            $table->text('memo')->nullable();
            $table->string('status', 20)->default('draft'); // draft|posted|void
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'receipt_no']);
            $table->index(['company_id', 'contact_id']);
            $table->index(['company_id', 'receipt_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_receipts');
    }
};

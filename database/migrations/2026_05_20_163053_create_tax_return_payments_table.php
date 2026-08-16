<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_return_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_return_id')->constrained()->cascadeOnDelete();
            $table->string('payment_no', 40);
            $table->date('payment_date');
            $table->string('direction', 12);                            // outgoing|incoming
            $table->string('status', 20)->default('draft');             // draft|posted|void
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->bigInteger('net_amount_cents')->default(0);          // clears tax_payable
            $table->bigInteger('penalty_cents')->default(0);             // outgoing only
            $table->foreignId('penalty_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->bigInteger('interest_cents')->default(0);            // outgoing: paid; incoming: received
            $table->foreignId('interest_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->bigInteger('commission_cents')->default(0);          // outgoing only
            $table->foreignId('commission_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->bigInteger('total_cents')->default(0);               // total moved through bank
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'payment_no']);
            $table->index(['company_id', 'tax_return_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_return_payments');
    }
};

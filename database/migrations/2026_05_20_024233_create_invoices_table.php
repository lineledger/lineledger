<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('invoice_no', 40);
            $table->date('invoice_date');
            $table->date('due_date');
            $table->foreignId('terms_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft|posted|partial|paid|void
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('amount_paid_cents')->default(0);
            $table->text('memo')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'invoice_no']);
            $table->index(['company_id', 'contact_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'invoice_date']);
            $table->index(['company_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

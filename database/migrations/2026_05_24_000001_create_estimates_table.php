<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('estimate_no', 40);
            $table->date('estimate_date');
            $table->date('expires_on')->nullable();
            $table->foreignId('terms_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending|accepted|rejected|converted|expired
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->text('memo')->nullable();
            $table->text('customer_message')->nullable();
            $table->foreignId('converted_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'estimate_no']);
            $table->index(['company_id', 'contact_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'estimate_date']);
            $table->index(['company_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimates');
    }
};

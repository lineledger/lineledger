<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The currencies a company transacts in. Exactly one row is the home currency
     * (is_home = true, mirroring companies.currency_code). Each active foreign
     * currency wires its own AR and AP control accounts (QuickBooks creates one
     * "Accounts Receivable (USD)" / "Accounts Payable (USD)" per foreign currency).
     */
    public function up(): void
    {
        Schema::create('company_currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('currency_code', 3);
            $table->boolean('is_home')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('ar_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('ap_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('gain_loss_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'currency_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_currencies');
    }
};

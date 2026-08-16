<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membership tiers (e.g. Individual, Family, Corporate). Each level carries a
     * default dues amount, billing cadence, and the revenue account credited when
     * dues are billed as invoices. Per-member overrides live on the members table.
     */
    public function up(): void
    {
        Schema::create('membership_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('default_dues_cents')->default(0);
            $table->string('billing_frequency')->default('annual');
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_terms_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->foreignId('default_tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_levels');
    }
};

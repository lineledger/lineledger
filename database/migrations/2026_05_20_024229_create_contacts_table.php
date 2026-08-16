<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('company_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('tax_number')->nullable();

            // Roles — a contact can be customer + vendor + employee simultaneously
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_vendor')->default(false);
            $table->boolean('is_employee')->default(false);

            // Billing address
            $table->string('billing_line1')->nullable();
            $table->string('billing_line2')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_region')->nullable();
            $table->string('billing_postal_code')->nullable();
            $table->string('billing_country', 2)->nullable();

            // Shipping (customers)
            $table->string('shipping_line1')->nullable();
            $table->string('shipping_line2')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_region')->nullable();
            $table->string('shipping_postal_code')->nullable();
            $table->string('shipping_country', 2)->nullable();

            // Defaults captured onto new documents
            $table->foreignId('default_terms_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->unsignedBigInteger('default_tax_code_id')->nullable();
            $table->unsignedBigInteger('default_income_account_id')->nullable();
            $table->unsignedBigInteger('default_expense_account_id')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            // Cached balances (recomputed on post)
            $table->bigInteger('ar_balance_cents')->default(0);
            $table->bigInteger('ap_balance_cents')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'is_customer']);
            $table->index(['company_id', 'is_vendor']);
            $table->index(['company_id', 'display_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales-receipt line items — the same shape as invoice_lines (item, account,
     * quantity, price, per-line discount, tax code, and class/location/fund
     * dimensions) so the revenue + COGS posting logic mirrors invoices exactly.
     */
    public function up(): void
    {
        Schema::create('sales_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->date('service_date')->nullable();
            $table->decimal('quantity', 12, 4)->default('1.0000');
            $table->bigInteger('unit_price_cents')->default(0);
            $table->integer('line_discount_cents')->default(0);
            $table->decimal('line_discount_pct', 7, 4)->nullable();
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('line_subtotal_cents')->default(0);
            $table->bigInteger('line_tax_cents')->default(0);
            $table->bigInteger('line_total_cents')->default(0);
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->foreignId('class_id')->nullable()->constrained('classifications')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained('funds')->nullOnDelete();
            $table->timestamps();

            $table->index('sales_receipt_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_receipt_lines');
    }
};

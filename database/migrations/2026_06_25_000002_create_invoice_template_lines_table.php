<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            // Nullable here (unlike posted invoice lines) so a partial template can
            // be built without coding every line to an account.
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->text('description')->nullable();
            $table->decimal('quantity', 15, 4)->default('1.0000');
            $table->bigInteger('unit_price_cents')->default(0);
            $table->decimal('line_discount_pct', 8, 4)->nullable();
            $table->decimal('line_markup_pct', 8, 4)->nullable();
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->foreignId('secondary_tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classifications')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedInteger('line_order')->default(0);
            $table->timestamps();

            $table->index('invoice_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_template_lines');
    }
};

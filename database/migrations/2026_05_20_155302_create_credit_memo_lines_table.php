<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_memo_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_memo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 4)->default('1.0000');
            $table->bigInteger('unit_price_cents')->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('line_subtotal_cents')->default(0);
            $table->bigInteger('line_tax_cents')->default(0);
            $table->bigInteger('line_total_cents')->default(0);
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();

            $table->index('credit_memo_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_memo_lines');
    }
};

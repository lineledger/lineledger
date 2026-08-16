<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->bigInteger('amount_cents')->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('tax_cents')->default(0); // computed at save time
            $table->bigInteger('tax_override_cents')->nullable();
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->foreignId('class_id')->nullable()->constrained('classifications')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained('funds')->nullOnDelete();
            $table->timestamps();

            $table->index('expense_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_lines');
    }
};

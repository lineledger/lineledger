<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Nullable here (unlike posted journal lines) so a partial template can
            // be built without coding every line to an account.
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->bigInteger('debit_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0);
            $table->text('memo')->nullable();
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classifications')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained('funds')->nullOnDelete();
            $table->unsignedInteger('line_order')->default(0);
            $table->timestamps();

            $table->index('journal_entry_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_template_lines');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('debit_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0);
            $table->text('memo')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();

            $table->index(['account_id', 'journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};

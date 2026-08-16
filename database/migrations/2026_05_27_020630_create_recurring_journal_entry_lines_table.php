<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('debit_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0);
            $table->text('memo')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable(); // AR/AP memo line
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->foreignId('class_id')->nullable()->constrained('classifications')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->timestamps();

            $table->index('recurring_journal_entry_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_journal_entry_lines');
    }
};

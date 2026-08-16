<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable(); // human label for the index
            $table->text('memo')->nullable();
            $table->string('frequency', 20); // weekly|monthly|quarterly|semi_annual|annual
            $table->date('start_date');
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1-31, monthly+ only
            $table->string('end_type', 20)->default('never'); // never|on_date|after_occurrences
            $table->date('end_date')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->unsignedInteger('occurrences_generated')->default(0);
            $table->date('next_run_date')->nullable();
            $table->timestamp('last_generated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('paused_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id']);
            $table->index(['is_active', 'next_run_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_journal_entries');
    }
};

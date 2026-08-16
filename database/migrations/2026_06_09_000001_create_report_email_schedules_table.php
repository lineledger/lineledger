<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_email_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Exactly one of the two targets is set (enforced in app code):
            // a single memorized report, or every report in a memorized group.
            $table->foreignId('memorized_report_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('memorized_report_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('recipients');
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->boolean('attach_xlsx')->default(false);
            $table->string('frequency', 20); // weekly|monthly|quarterly|semi_annual|annual
            $table->date('start_date');
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1-31, monthly+ only
            $table->string('end_type', 20)->default('never'); // never|on_date|after_occurrences
            $table->date('end_date')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->unsignedInteger('occurrences_generated')->default(0);
            $table->date('next_run_date')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('paused_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id']);
            $table->index(['is_active', 'next_run_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_email_schedules');
    }
};

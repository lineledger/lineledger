<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A finalized year-end slip filing (T4 / RL-1 / T4A) for one company + year.
        // Existence of a row means the year is locked: the report pages and the
        // employee portal read the snapshot, not the live calculator. Deleting the
        // row (unlock) returns the year to live-computed "draft".
        Schema::create('payroll_slip_filings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('slip_type', 10); // t4 | rl1 | t4a
            $table->unsignedSmallInteger('year');
            $table->timestamp('finalized_at');
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary'); // snapshot of the calculator's summary() at finalize time
            $table->timestamps();

            $table->unique(['company_id', 'slip_type', 'year'], 'payroll_slip_filings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_slip_filings');
    }
};

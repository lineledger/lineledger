<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_schedule_id')->nullable()->constrained('payroll_schedules')->nullOnDelete();
            $table->string('run_no', 40);
            $table->date('period_start_date');
            $table->date('period_end_date');
            $table->date('pay_date'); // GL entry_date; lock-date check keys here
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft|calculated|posted|paid|void

            // Header roll-ups (recomputed on calculate).
            $table->bigInteger('gross_cents')->default(0);
            $table->bigInteger('total_deductions_cents')->default(0);
            $table->bigInteger('total_employer_cost_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'run_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'pay_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_runs');
    }
};

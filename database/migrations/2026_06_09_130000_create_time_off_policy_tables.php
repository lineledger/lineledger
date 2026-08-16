<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company-level time-off policy presets (vacation/sick/personal): an accrual
        // schedule + annual cap + carryover. Assigned to employees; balances live in
        // employee_accrual_balances keyed on `code`.
        Schema::create('time_off_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 40); // balance + use-earning join key (e.g. sick, personal)
            $table->string('category', 20);       // vacation|sick|personal|bereavement|other|unpaid
            $table->string('unit', 10);           // hours|dollars
            $table->string('accrual_method', 20); // per_pay_period|per_hour_worked|beginning_of_year|anniversary|manual
            $table->decimal('rate_hours', 8, 2)->default(0); // flat hours per period / per year (lump)
            $table->integer('rate_bp')->default(0);          // basis points: % of hours worked, or % of earnings (dollars)
            $table->decimal('annual_cap_hours', 10, 2)->nullable();
            $table->bigInteger('annual_cap_cents')->nullable();
            $table->decimal('carryover_max_hours', 10, 2)->nullable();
            $table->bigInteger('carryover_max_cents')->nullable();
            $table->boolean('paid')->default(true);
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('liability_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_default')->default(false); // "use for future employees"
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'top_company_code_unique');
        });

        // Per-employee assignment of a policy, with an opening balance, optional rate
        // override, and the last lump-grant date (idempotency for the BOY/anniversary command).
        Schema::create('employee_time_off_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_payroll_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('time_off_policy_id')->constrained()->cascadeOnDelete();
            $table->decimal('opening_balance_hours', 10, 2)->default(0);
            $table->bigInteger('opening_balance_cents')->default(0);
            $table->decimal('rate_override_hours', 8, 2)->nullable();
            $table->integer('rate_override_bp')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('last_accrued_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['employee_payroll_profile_id', 'time_off_policy_id'], 'etop_profile_policy_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_time_off_policies');
        Schema::dropIfExists('time_off_policies');
    }
};

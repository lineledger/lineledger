<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-pay accrual snapshot (vacation accrual, sick hours, banked time).
        // Dollar accruals post DR expense / CR liability; hour accruals only move a
        // balance. Rebuilt each calculation, like the earning/deduction snapshots.
        Schema::create('pay_run_line_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_run_line_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('calc_basis', 30); // hours | dollars | percent_of_earnings | cents_per_hour | percent_of_hours | units | miles
            $table->bigInteger('amount_cents')->default(0); // dollar accruals
            $table->decimal('hours', 8, 2)->default(0);     // hour accruals
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('liability_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->integer('line_order')->default(0);
            $table->timestamps();

            $table->index('pay_run_line_id');
        });

        // Running per-employee accrual balances (hours and/or dollars), kept in step
        // with posted runs (incremented on post, reversed on void).
        Schema::create('employee_accrual_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_payroll_profile_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->decimal('balance_hours', 10, 2)->default(0);
            $table->bigInteger('balance_cents')->default(0);
            $table->decimal('accrued_ytd_hours', 10, 2)->default(0);
            $table->bigInteger('accrued_ytd_cents')->default(0);
            $table->decimal('used_ytd_hours', 10, 2)->default(0);
            $table->bigInteger('used_ytd_cents')->default(0);
            $table->timestamps();

            $table->unique(['employee_payroll_profile_id', 'code'], 'eab_profile_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_run_line_accruals');
        Schema::dropIfExists('employee_accrual_balances');
    }
};

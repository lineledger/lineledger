<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Banked overtime (time-off-in-lieu): per-employee opt-in with the
        // written-agreement date employment standards require, and an optional
        // accrual-multiplier override (null = the province default from
        // BankedOvertimeRules; e.g. a pre-2019 Alberta agreement keeps 1.5×).
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->boolean('banked_overtime_enabled')->default(false)->after('vacation_balance_cents');
            $table->date('banked_overtime_agreement_date')->nullable()->after('banked_overtime_enabled');
            $table->smallInteger('banked_overtime_multiplier_bp')->nullable()->after('banked_overtime_agreement_date');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->dropColumn(['banked_overtime_enabled', 'banked_overtime_agreement_date', 'banked_overtime_multiplier_bp']);
        });
    }
};

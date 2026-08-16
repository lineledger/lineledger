<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Opt-in accountant-grade banked overtime: earning into the bank
            // posts DR wage expense / CR Banked Time Payable (2435); taking the
            // hours relieves the liability instead of re-expensing the wages.
            // Off = hours-only tracking with no GL until the day is paid.
            $table->boolean('payroll_banked_overtime_liability')->default(false)->after('payroll_overtime_weekly_threshold_hours');
            // The employee portal's team time-off calendar (approved absences,
            // names + dates only). On by default; a privacy-minded company can
            // turn it off.
            $table->boolean('portal_team_calendar')->default(true)->after('payroll_banked_overtime_liability');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['payroll_banked_overtime_liability', 'portal_team_calendar']);
        });
    }
};

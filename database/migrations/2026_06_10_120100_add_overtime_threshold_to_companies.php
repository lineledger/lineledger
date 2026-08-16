<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Weekly hours past which pulled time-tracking hours are split into a 1.5×
            // overtime earning. Null = no auto-split (the payroll default). This is a
            // single-threshold approximation, not per-province employment-standards rules.
            $table->decimal('payroll_overtime_weekly_threshold_hours', 6, 2)->nullable()->after('payroll_standard_annual_hours');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('payroll_overtime_weekly_threshold_hours');
        });
    }
};

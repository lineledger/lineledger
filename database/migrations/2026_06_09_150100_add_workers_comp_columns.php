<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            // Some workers (e.g. executive officers) are exempt from WC coverage; a
            // per-employee rate override carries a different rate group than the
            // province default.
            $table->boolean('workers_comp_exempt')->default(false)->after('qpip_exempt');
            $table->integer('workers_comp_rate_bp')->nullable()->after('workers_comp_exempt');
        });

        Schema::table('pay_run_lines', function (Blueprint $table) {
            // Computed employer workers'-comp levy for the line (0 for Quebec — CNESST
            // covers QC — and for exempt employees).
            $table->bigInteger('wc_employer_computed_cents')->default(0)->after('cnesst_employer_computed_cents');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->dropColumn(['workers_comp_exempt', 'workers_comp_rate_bp']);
        });

        Schema::table('pay_run_lines', function (Blueprint $table) {
            $table->dropColumn('wc_employer_computed_cents');
        });
    }
};

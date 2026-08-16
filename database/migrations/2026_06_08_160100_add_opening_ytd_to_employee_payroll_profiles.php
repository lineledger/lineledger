<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            // Mid-year opening YTD: prior-employer (or prior-system) statutory
            // accumulators so the annual CPP/EI/QPP/QPIP caps and the CPP2 band are
            // correct for an employee onboarded partway through the year. Mirrors
            // the nine YtdTotals fields. PayrollYtdService adds these to the posted
            // sums, but only for the tax year named by opening_balances_as_of.
            $table->bigInteger('opening_pensionable_cents')->default(0);
            $table->bigInteger('opening_insurable_cents')->default(0);
            $table->bigInteger('opening_cpp_employee_cents')->default(0);
            $table->bigInteger('opening_cpp2_employee_cents')->default(0);
            $table->bigInteger('opening_ei_employee_cents')->default(0);
            $table->bigInteger('opening_qpp_employee_cents')->default(0);
            $table->bigInteger('opening_qpp2_employee_cents')->default(0);
            $table->bigInteger('opening_qpip_employee_cents')->default(0);
            $table->bigInteger('opening_qpip_insurable_cents')->default(0);
            $table->date('opening_balances_as_of')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'opening_pensionable_cents', 'opening_insurable_cents',
                'opening_cpp_employee_cents', 'opening_cpp2_employee_cents',
                'opening_ei_employee_cents', 'opening_qpp_employee_cents',
                'opening_qpp2_employee_cents', 'opening_qpip_employee_cents',
                'opening_qpip_insurable_cents', 'opening_balances_as_of',
            ]);
        });
    }
};

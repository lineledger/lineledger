<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            // Income-tax exemption (e.g. status-Indian exempt employment, tax
            // treaty): the engine withholds no income tax — federal, provincial,
            // and Quebec — while CPP/EI/QPP/QPIP still apply. Mirrors the
            // cpp_exempt/ei_exempt switches.
            $table->boolean('income_tax_exempt')->default(false)->after('ei_exempt');

            // T4127 "F1/F2/HD" authorized annual deductions (e.g. CRA-/RQ-approved
            // T1213 amounts, support payments) that reduce annual taxable income.
            $table->bigInteger('authorized_annual_deductions_cents')->default(0)->after('additional_tax_per_period_cents');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->dropColumn(['income_tax_exempt', 'authorized_annual_deductions_cents']);
        });
    }
};

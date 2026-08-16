<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table) {
            // Quebec statutory: computed + nullable override (effective = override ?? computed).
            // Stay 0 on non-QC lines so every downstream remittance/slip sum is filter-free.
            foreach ([
                'qpp_employee', 'qpp_employer', 'qpp2_employee', 'qpp2_employer',
                'qpip_employee', 'qpip_employer', 'quebec_tax',
            ] as $component) {
                $table->bigInteger($component.'_computed_cents')->default(0)->after('additional_tax_override_cents');
                $table->bigInteger($component.'_override_cents')->nullable()->after($component.'_computed_cents');
            }

            // QPIP insurable base (RL-1 box I); its $98,000 cap differs from the EI MIE.
            $table->bigInteger('qpip_insurable_cents')->default(0)->after('ei_insurable_cents');

            // Employer-only Quebec levies, computed from company settings in CalculatePayRun.
            $table->bigInteger('qhsf_employer_computed_cents')->default(0)->after('quebec_tax_override_cents');
            $table->bigInteger('cnesst_employer_computed_cents')->default(0)->after('qhsf_employer_computed_cents');

            // YTD snapshots frozen at post (QPP shares the pensionable accumulator; QPIP
            // needs its own insurable accumulator for the $98,000 cap).
            $table->bigInteger('ytd_qpp_employee_cents')->default(0)->after('ytd_ei_employee_cents');
            $table->bigInteger('ytd_qpp2_employee_cents')->default(0)->after('ytd_qpp_employee_cents');
            $table->bigInteger('ytd_qpip_employee_cents')->default(0)->after('ytd_qpp2_employee_cents');
            $table->bigInteger('ytd_qpip_insurable_cents')->default(0)->after('ytd_qpip_employee_cents');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table) {
            $columns = ['qpip_insurable_cents', 'qhsf_employer_computed_cents', 'cnesst_employer_computed_cents',
                'ytd_qpp_employee_cents', 'ytd_qpp2_employee_cents', 'ytd_qpip_employee_cents', 'ytd_qpip_insurable_cents'];

            foreach ([
                'qpp_employee', 'qpp_employer', 'qpp2_employee', 'qpp2_employer',
                'qpip_employee', 'qpip_employer', 'quebec_tax',
            ] as $component) {
                $columns[] = $component.'_computed_cents';
                $columns[] = $component.'_override_cents';
            }

            $table->dropColumn($columns);
        });
    }
};

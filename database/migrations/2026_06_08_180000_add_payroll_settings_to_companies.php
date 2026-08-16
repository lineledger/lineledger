<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Standard full-time annual hours used to derive a salaried employee's
            // hourly rate for overtime (defaults to 2080 = 52 weeks × 40h).
            $table->integer('payroll_standard_annual_hours')->default(2080)->after('cnesst_rate_bp');

            // CRA payroll program identity (the BN + RP program account, e.g. RP0001)
            // and the payroll contact + work location used on remittances and slips.
            $table->string('payroll_business_number')->nullable()->after('payroll_standard_annual_hours');
            $table->string('payroll_rp_account')->nullable()->after('payroll_business_number');
            $table->string('payroll_contact_name')->nullable()->after('payroll_rp_account');
            $table->string('payroll_contact_email')->nullable()->after('payroll_contact_name');
            $table->string('payroll_contact_phone')->nullable()->after('payroll_contact_email');
            $table->string('payroll_work_location')->nullable()->after('payroll_contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'payroll_standard_annual_hours', 'payroll_business_number', 'payroll_rp_account',
                'payroll_contact_name', 'payroll_contact_email', 'payroll_contact_phone', 'payroll_work_location',
            ]);
        });
    }
};

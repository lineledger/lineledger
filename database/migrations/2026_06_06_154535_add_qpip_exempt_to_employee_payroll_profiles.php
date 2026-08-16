<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            // Rare QPIP exemption (mirrors cpp_exempt/ei_exempt); only meaningful for QC employees.
            $table->boolean('qpip_exempt')->default(false)->after('ei_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->dropColumn('qpip_exempt');
        });
    }
};

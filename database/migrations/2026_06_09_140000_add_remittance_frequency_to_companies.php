<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // The CRA-assigned remitter frequency (also drives the Revenu Québec
            // periods): quarterly | monthly | accelerated_1 | accelerated_2. Sets
            // each remittance period's bounds + due date.
            $table->string('payroll_remittance_frequency', 20)->nullable()->after('payroll_federally_regulated');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('payroll_remittance_frequency');
        });
    }
};

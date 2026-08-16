<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            // CPT30: a CPP contributor aged 65–70 may elect to stop contributing.
            // The election takes effect the first of the month after it is filed.
            // ROC only — QPP has no equivalent election. date_of_birth (already
            // present) drives the under-18 start and over-70 stop boundaries.
            $table->date('cpt30_election_date')->nullable()->after('qpip_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->dropColumn('cpt30_election_date');
        });
    }
};

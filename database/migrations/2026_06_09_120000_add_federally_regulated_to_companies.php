<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // A federally regulated employer (banks, telecom, interprovincial
            // transport, etc.) follows the Canada Labour Code Part III for pay
            // statements rather than the employee's provincial standards. This
            // changes jurisdiction resolution, so it is a column, not a setting.
            // Per-item show/hide toggles live in the existing companies.settings JSON.
            $table->boolean('payroll_federally_regulated')->default(false)->after('payroll_work_location');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('payroll_federally_regulated');
        });
    }
};

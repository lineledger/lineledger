<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_packages', function (Blueprint $table) {
            // App\Support\Reporting\ComparisonPeriod basis: off | prior_period | prior_year.
            $table->string('comparison_basis', 40)->default('off')->after('period_preset');
        });
    }

    public function down(): void
    {
        Schema::table('report_packages', function (Blueprint $table) {
            $table->dropColumn('comparison_basis');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Marks earnings taxed by the CRA T4127 bonus/retro method (withhold
        // the annual-tax DELTA the lump causes, instead of annualizing it as
        // period income). Snapshotted so the YTD bonus accumulator — which
        // positions later bonuses in the right bracket — reads posted history.
        Schema::table('pay_run_line_earnings', function (Blueprint $table) {
            $table->boolean('is_bonus_method')->default(false)->after('is_taxable');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_line_earnings', function (Blueprint $table) {
            $table->dropColumn('is_bonus_method');
        });
    }
};

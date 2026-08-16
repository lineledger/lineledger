<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What kind of hours a time entry represents (regular work, overtime,
        // sick, vacation, …). 'regular' preserves the prior behaviour: regular
        // hours fill hours_worked; any other code becomes its own earning when
        // pulled into a pay run.
        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('pay_code', 40)->default('regular')->after('hours');
        });

        // Marks pay-run manual earnings the time-entry pull generated (vs rows
        // an operator entered by hand), so a re-pull replaces only its own rows.
        Schema::table('pay_run_line_manual_earnings', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('line_order');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn('pay_code');
        });

        Schema::table('pay_run_line_manual_earnings', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};

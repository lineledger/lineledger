<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a month-based schedule land on the last day or the last business day
     * of the month instead of a fixed day_of_month (see RecurrenceDayAnchor).
     * Every existing row keeps its current behaviour via the default.
     */
    private const TABLES = ['recurring_documents', 'recurring_journal_entries', 'report_email_schedules'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('day_anchor', 32)->default('day_of_month')->after('day_of_month');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('day_anchor');
            });
        }
    }
};

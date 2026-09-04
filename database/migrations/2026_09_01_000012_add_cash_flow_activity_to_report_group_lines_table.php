<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_group_lines', function (Blueprint $table) {
            $table->string('cash_flow_activity', 12)->nullable()->after('report_group_section_id');
        });
    }

    public function down(): void
    {
        Schema::table('report_group_lines', function (Blueprint $table) {
            $table->dropColumn('cash_flow_activity');
        });
    }
};

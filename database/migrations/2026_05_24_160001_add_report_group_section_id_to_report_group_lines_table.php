<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_group_lines', function (Blueprint $table) {
            $table->foreignId('report_group_section_id')->nullable()->after('report_group_id')
                ->constrained('report_group_sections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('report_group_lines', function (Blueprint $table) {
            $table->dropForeign(['report_group_section_id']);
            $table->dropColumn('report_group_section_id');
        });
    }
};

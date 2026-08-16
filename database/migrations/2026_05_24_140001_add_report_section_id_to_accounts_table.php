<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('report_section_id')->nullable()->after('parent_id')
                ->constrained('report_sections')->nullOnDelete();
            $table->index(['company_id', 'report_section_id']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['report_section_id']);
            $table->dropIndex(['company_id', 'report_section_id']);
            $table->dropColumn('report_section_id');
        });
    }
};

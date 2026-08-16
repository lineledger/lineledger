<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tax_returns', function (Blueprint $table) {
            $table->json('excluded_journal_line_ids')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_returns', function (Blueprint $table) {
            $table->dropColumn('excluded_journal_line_ids');
        });
    }
};

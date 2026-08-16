<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->timestamp('cleared_at')->nullable()->after('line_order');
            $table->index(['account_id', 'cleared_at']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'cleared_at']);
            $table->dropColumn('cleared_at');
        });
    }
};

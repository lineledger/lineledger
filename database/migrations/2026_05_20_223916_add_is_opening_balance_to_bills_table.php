<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->boolean('is_opening_balance')->default(false)->after('memo');
            $table->index(['company_id', 'is_opening_balance']);
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_opening_balance']);
            $table->dropColumn('is_opening_balance');
        });
    }
};

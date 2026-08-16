<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Per-asset opt-in for nightly straight-line book-depreciation drafts.
            $table->boolean('auto_depreciate')->default(false)->after('useful_life_months');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('auto_depreciate');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-user: whether the daily insight card shows on the dashboard.
            // Closing a card only hides that day's insight; this switch is the
            // durable opt-out (and the visible guarantee that new ones appear).
            $table->boolean('show_daily_insights')->default(true)->after('calculator_mode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('show_daily_insights');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_run_line_earnings', function (Blueprint $table) {
            // Hours behind an hours-based earning (overtime, and especially a time-off
            // "use" earning) so a paid/unpaid leave can draw its hours down from the
            // matching accrual balance on post.
            $table->decimal('hours', 8, 2)->default(0)->after('amount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_line_earnings', function (Blueprint $table) {
            $table->dropColumn('hours');
        });
    }
};

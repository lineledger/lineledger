<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // When true, owners and admins of this company must have two-factor
            // authentication enabled before they can use the app (SOC 2 CC6.1).
            // Default false so enabling it is a deliberate, opt-in rollout.
            $table->boolean('require_two_factor')
                ->default(false)
                ->after('auto_apply_customer_credits');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('require_two_factor');
        });
    }
};

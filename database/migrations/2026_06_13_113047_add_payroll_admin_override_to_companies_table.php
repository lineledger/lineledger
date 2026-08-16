<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Site-admin override that turns Payroll on for one specific company even
     * when the platform-wide Payroll section is globally disabled. Used by the
     * operator to grant a single tenant early/beta/comp access without flipping
     * the kill switch back on for everyone. When set the override also implies
     * the per-company `features_payroll` opt-in.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('payroll_admin_enabled_at')->nullable()->after('features_payroll');
            $table->string('payroll_admin_enabled_by')->nullable()->after('payroll_admin_enabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'payroll_admin_enabled_at',
                'payroll_admin_enabled_by',
            ]);
        });
    }
};

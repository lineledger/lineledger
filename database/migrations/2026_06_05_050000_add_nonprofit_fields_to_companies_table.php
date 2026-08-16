<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Non-profit tier + contribution-method settings. `legal_structure` refines
     * the spectrum (unincorporated association → non-profit corporation →
     * registered charity); `charity_registration_number` is the CRA BN/RR number
     * for registered charities; `contribution_method` selects the ASNPO method
     * (deferral vs restricted fund) the company accounts under. All nullable —
     * for-profit companies have none. Existing non-profits are backfilled to the
     * deferral method so reports have a concrete value to key off.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('legal_structure', 40)->nullable()->after('organization_type');
            $table->string('charity_registration_number', 32)->nullable()->after('legal_structure');
            $table->string('contribution_method', 20)->nullable()->after('charity_registration_number');
        });

        DB::table('companies')
            ->whereIn('organization_type', ['non_profit', 'charity'])
            ->whereNull('contribution_method')
            ->update(['contribution_method' => 'deferral']);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'legal_structure',
                'charity_registration_number',
                'contribution_method',
            ]);
        });
    }
};

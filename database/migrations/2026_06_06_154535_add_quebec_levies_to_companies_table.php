<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Quebec employer levies. Basis points (cf. vacation_rate_bp); 0 = not levied.
            // QHSF is on Quebec gross; CNESST on Quebec insurable earnings.
            $table->integer('qhsf_rate_bp')->default(0)->after('features_payroll');
            $table->integer('cnesst_rate_bp')->default(0)->after('qhsf_rate_bp');
            // WSDRF (1% training reconciliation) reported on the RL-1 Summary when applicable.
            $table->boolean('wsdrf_applicable')->default(false)->after('cnesst_rate_bp');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['qhsf_rate_bp', 'cnesst_rate_bp', 'wsdrf_applicable']);
        });
    }
};

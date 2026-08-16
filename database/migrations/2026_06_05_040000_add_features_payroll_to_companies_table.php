<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Opt-in: payroll is Canada-only and requires deliberate setup, so it
            // defaults off unlike the always-on accounting feature toggles.
            $table->boolean('features_payroll')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('features_payroll');
        });
    }
};

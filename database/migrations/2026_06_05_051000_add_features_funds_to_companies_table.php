<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt-in flag for the Fund dimension (the ASNPO restricted fund method). Gated
     * together with contribution_method === restricted_fund (see Company::tracksFunds()).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('features_funds')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('features_funds');
        });
    }
};

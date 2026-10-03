<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 8)->nullable()->after('calculator_mode');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('locale', 8)->nullable()->after('timezone');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('locale', 8)->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('locale');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};

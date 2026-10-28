<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which invoice layout a form style prints with. Existing styles keep the
     * classic layout they have always had.
     */
    public function up(): void
    {
        Schema::table('form_styles', function (Blueprint $table) {
            $table->string('layout', 20)->default('classic')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('form_styles', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};

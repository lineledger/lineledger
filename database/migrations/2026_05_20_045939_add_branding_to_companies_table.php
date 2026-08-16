<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('settings');
            $table->string('brand_name')->nullable()->after('logo_path');
            $table->string('brand_initials', 4)->nullable()->after('brand_name');
            $table->string('brand_text_color', 9)->nullable()->after('brand_initials');
            $table->string('brand_background_color', 9)->nullable()->after('brand_text_color');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'logo_path',
                'brand_name',
                'brand_initials',
                'brand_text_color',
                'brand_background_color',
            ]);
        });
    }
};

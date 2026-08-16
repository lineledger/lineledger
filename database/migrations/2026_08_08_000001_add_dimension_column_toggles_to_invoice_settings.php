<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the Class and Location line columns optional on the invoice form,
     * mirroring the existing Account/Unit toggles. Defaults on, so companies
     * tracking dimensions keep seeing them until an owner hides them from the
     * invoice Fields menu. The columns only ever render when the matching
     * company feature (classes/locations) is enabled.
     */
    public function up(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->boolean('show_class_column')->default(true)->after('show_markup_column');
            $table->boolean('show_location_column')->default(true)->after('show_class_column');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->dropColumn(['show_class_column', 'show_location_column']);
        });
    }
};

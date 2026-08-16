<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a toggle for the Template picker shown when creating an invoice. Defaults
     * to visible; owners can hide it from the Fields menu when they don't use
     * invoice templates.
     */
    public function up(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->boolean('show_template')->default(true)->after('show_terms');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->dropColumn('show_template');
        });
    }
};

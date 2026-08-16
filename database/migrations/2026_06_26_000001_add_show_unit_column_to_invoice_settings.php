<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the Unit (price) column on the invoice line table optional, mirroring
     * the existing Account toggle. Defaults on, so existing invoices keep showing
     * the unit price until an owner hides it from the invoice Columns menu.
     */
    public function up(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->boolean('show_unit_column')->default(true)->after('show_account_column');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->dropColumn('show_unit_column');
        });
    }
};

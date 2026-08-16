<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add toggles for the Disc %, Markup % and document-discount line controls on
     * the invoice entry form. These default to hidden so a new company starts with
     * a lean invoice; owners re-show any of them from the Fields menu.
     */
    public function up(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->boolean('show_discount_column')->default(false)->after('show_account_column');
            $table->boolean('show_markup_column')->default(false)->after('show_discount_column');
            $table->boolean('show_document_discount')->default(false)->after('show_markup_column');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->dropColumn([
                'show_discount_column',
                'show_markup_column',
                'show_document_discount',
            ]);
        });
    }
};

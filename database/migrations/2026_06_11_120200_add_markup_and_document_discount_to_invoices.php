<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-line markup (symmetric to the existing per-line discount) plus a
     * document-level discount on the invoice header. The document discount posts
     * to a "Sales Discounts" contra-revenue account so gross sales stay visible.
     */
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->integer('line_markup_cents')->default(0)->after('line_discount_pct');
            $table->decimal('line_markup_pct', 7, 4)->nullable()->after('line_markup_cents');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->integer('document_discount_cents')->default(0)->after('total_cents');
            $table->decimal('document_discount_pct', 7, 4)->nullable()->after('document_discount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['line_markup_cents', 'line_markup_pct']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['document_discount_cents', 'document_discount_pct']);
        });
    }
};

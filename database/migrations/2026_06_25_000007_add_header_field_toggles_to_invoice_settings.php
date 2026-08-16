<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the single coarse `show_company_info` toggle with per-field control
     * over the printed-document header (name, legal name, address, phone, email,
     * website). The old column is kept for backup compatibility but no longer read
     * by the PDF templates. Defaults mirror the previous behaviour: name + address
     * + phone shown; legal name, email and website hidden.
     */
    public function up(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->boolean('show_company_name')->default(true)->after('show_company_info');
            $table->boolean('show_legal_name')->default(false)->after('show_company_name');
            $table->boolean('show_company_address')->default(true)->after('show_legal_name');
            $table->boolean('show_company_phone')->default(true)->after('show_company_address');
            $table->boolean('show_company_email')->default(false)->after('show_company_phone');
            $table->boolean('show_company_website')->default(false)->after('show_company_email');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->dropColumn([
                'show_company_name',
                'show_legal_name',
                'show_company_address',
                'show_company_phone',
                'show_company_email',
                'show_company_website',
            ]);
        });
    }
};

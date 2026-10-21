<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maps each account onto a line of the UK statutory accounts (FRS 102
     * Section 1A / FRS 105). Both columns are optional: an account without a
     * line falls back to a default from its subtype and name, and without a
     * caption presents under its own name (or a default analysis caption), so
     * nothing needs backfilling.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('statutory_line', 40)->nullable()->after('gifi_code');
            $table->string('statutory_label', 120)->nullable()->after('statutory_line');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['statutory_line', 'statutory_label']);
        });
    }
};

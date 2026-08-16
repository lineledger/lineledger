<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen tax_codes.rate_basis_points from an integer to decimal(9,3) so rates that
 * aren't whole basis points can be stored exactly — most importantly Quebec's QST
 * at 9.975% (997.5 basis points). Existing whole-number rates (500, 1300, …) are
 * preserved verbatim; the column still means basis points (1% = 100), now with up
 * to three decimal places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_codes', function (Blueprint $table) {
            $table->decimal('rate_basis_points', 9, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tax_codes', function (Blueprint $table) {
            $table->unsignedInteger('rate_basis_points')->default(0)->change();
        });
    }
};

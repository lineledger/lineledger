<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a user override the auto-computed tax on a cheque or bill line — e.g.
     * to match a vendor's invoice to the cent when their rounding differs from
     * ours. Null means "use the tax code's computed amount"; a non-null value is
     * the exact tax the user typed and is what posts to the GL.
     */
    public function up(): void
    {
        Schema::table('cheque_lines', function (Blueprint $table) {
            $table->integer('tax_override_cents')->nullable()->after('tax_cents');
        });

        Schema::table('bill_lines', function (Blueprint $table) {
            $table->integer('tax_override_cents')->nullable()->after('line_tax_cents');
        });
    }

    public function down(): void
    {
        Schema::table('cheque_lines', function (Blueprint $table) {
            $table->dropColumn('tax_override_cents');
        });

        Schema::table('bill_lines', function (Blueprint $table) {
            $table->dropColumn('tax_override_cents');
        });
    }
};

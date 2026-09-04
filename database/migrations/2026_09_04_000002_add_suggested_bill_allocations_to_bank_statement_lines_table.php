<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When one outflow settles several open bills, the offered/chosen split:
     * [{bill_id, amount_cents}, …] summing to the line. Used only for N > 1 —
     * suggested_bill_id stays the single-bill fast path and the two are never
     * both set. Never queried beyond null / not-null (no JSON predicates).
     */
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->json('suggested_bill_allocations')->nullable()->after('suggested_bill_id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropColumn('suggested_bill_allocations');
        });
    }
};

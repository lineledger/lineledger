<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A "bases-only" earning is the notional, non-cash counterpart of a taxable
        // employer benefit: it feeds the CPP/EI/QPIP/income-tax bases (so the tax is
        // taken out of net pay) but is NEVER paid as cash — excluded from gross AND
        // net. The inverse of add_to_net_pay_only (cash, but not income).
        Schema::table('pay_run_line_earnings', function (Blueprint $table) {
            $table->boolean('add_to_bases_only')->default(false)->after('add_to_net_pay_only');
        });

        // The recurring-item flag that, on a Benefit (contribution) item, marks it as
        // a taxable benefit so the engine emits the notional earning above.
        Schema::table('employee_recurring_items', function (Blueprint $table) {
            $table->boolean('add_to_bases_only')->default(false)->after('add_to_net_pay_only');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_line_earnings', function (Blueprint $table) {
            $table->dropColumn('add_to_bases_only');
        });

        Schema::table('employee_recurring_items', function (Blueprint $table) {
            $table->dropColumn('add_to_bases_only');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_run_line_earnings', function (Blueprint $table) {
            // A net-pay-only earning (reimbursement, expense advance) is paid to the
            // employee but is NOT employment income: excluded from gross/box-14 and
            // every CPP/EI/tax base, while still flowing into net pay.
            $table->boolean('add_to_net_pay_only')->default(false)->after('is_taxable');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_line_earnings', function (Blueprint $table) {
            $table->dropColumn('add_to_net_pay_only');
        });
    }
};

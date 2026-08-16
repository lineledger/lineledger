<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Per-company cheque print calibration (points). Null falls back to
            // config('cheque.offset_*'). Lets each company self-align its printer.
            $table->decimal('cheque_offset_x', 6, 2)->nullable()->after('warn_duplicate_bill_no');
            $table->decimal('cheque_offset_y', 6, 2)->nullable()->after('cheque_offset_x');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['cheque_offset_x', 'cheque_offset_y']);
        });
    }
};

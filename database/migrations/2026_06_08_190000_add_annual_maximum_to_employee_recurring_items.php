<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_recurring_items', function (Blueprint $table) {
            // Optional annual cap (per calendar year) for a deduction or
            // contribution. Once the YTD posted total for the item's code reaches
            // this, the engine stops applying it. Null = no cap.
            $table->bigInteger('annual_maximum_cents')->nullable()->after('percent_bp');
        });
    }

    public function down(): void
    {
        Schema::table('employee_recurring_items', function (Blueprint $table) {
            $table->dropColumn('annual_maximum_cents');
        });
    }
};

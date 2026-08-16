<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-province workers'-comp (WSIB/WCB) rate the employer is assessed at.
        // One row per province the company operates in; Quebec stays on CNESST.
        Schema::create('workers_comp_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('province', 2);
            $table->integer('rate_bp')->default(0); // rate per $100 of assessable payroll, in basis points ($2.50/$100 = 250)
            $table->bigInteger('annual_max_assessable_cents')->nullable(); // per-worker max assessable earnings
            $table->string('board_account')->nullable(); // WCB/WSIB account number (for the remittance)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'province'], 'wcs_company_province_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers_comp_settings');
    }
};

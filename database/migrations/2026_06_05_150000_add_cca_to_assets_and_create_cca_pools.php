<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Capital cost allowance (CCA) support for the T2125. Each asset category is
     * assigned a CCA class; assets in that category pool for the declining-balance
     * calculation. The cca_pools table stores the opening undepreciated capital
     * cost (UCC) carried into each tax year per class, which the user maintains.
     */
    public function up(): void
    {
        Schema::table('asset_categories', function (Blueprint $table) {
            $table->string('cca_class', 8)->nullable()->after('default_useful_life_months');
        });

        Schema::create('cca_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('tax_year');
            $table->string('cca_class', 8);
            $table->bigInteger('opening_ucc_cents')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'tax_year', 'cca_class']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cca_pools');

        Schema::table('asset_categories', function (Blueprint $table) {
            $table->dropColumn('cca_class');
        });
    }
};

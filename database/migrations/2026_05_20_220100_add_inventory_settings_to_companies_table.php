<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('costing_method', 30)->default('weighted_average')->after('auto_apply_customer_credits');
            $table->foreignId('default_inventory_asset_account_id')->nullable()
                ->after('costing_method')
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_cogs_account_id')->nullable()
                ->after('default_inventory_asset_account_id')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_inventory_asset_account_id');
            $table->dropConstrainedForeignId('default_cogs_account_id');
            $table->dropColumn('costing_method');
        });
    }
};

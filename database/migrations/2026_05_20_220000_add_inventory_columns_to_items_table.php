<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('track_inventory')->default(false)->after('description');
            $table->foreignId('inventory_asset_account_id')->nullable()->after('income_account_id')
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('cogs_account_id')->nullable()->after('expense_account_id')
                ->constrained('accounts')->nullOnDelete();
            $table->decimal('reorder_point', 14, 4)->nullable()->after('default_price_cents');
            $table->decimal('qty_on_hand_cached', 14, 4)->default(0)->after('reorder_point');
            $table->bigInteger('unit_cost_cents_cached')->default(0)->after('qty_on_hand_cached');

            $table->index(['company_id', 'track_inventory']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'track_inventory']);
            $table->dropConstrainedForeignId('inventory_asset_account_id');
            $table->dropConstrainedForeignId('cogs_account_id');
            $table->dropColumn([
                'track_inventory',
                'reorder_point',
                'qty_on_hand_cached',
                'unit_cost_cents_cached',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * QuickBooks-style item typing + category. `type` collapses the old
     * track_inventory boolean into an explicit enum; existing tracked items
     * become Inventory, the rest Service.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('type', 20)->default('service')->after('description');
            $table->foreignId('item_category_id')->nullable()->after('type')->constrained('item_categories')->nullOnDelete();
        });

        DB::table('items')->where('track_inventory', true)->update(['type' => 'inventory']);
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_category_id');
            $table->dropColumn('type');
        });
    }
};

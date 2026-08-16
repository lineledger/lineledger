<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('qty_change', 14, 4); // signed
            $table->bigInteger('unit_cost_cents')->default(0); // for receipts; for issues we use costing-method cost
            $table->unsignedInteger('line_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['stock_adjustment_id', 'line_order']);
            $table->index(['item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_lines');
    }
};

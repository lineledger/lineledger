<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_movement_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty_remaining', 14, 4);
            $table->bigInteger('unit_cost_cents');
            $table->timestamps();

            $table->index(['company_id', 'item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_layers');
    }
};

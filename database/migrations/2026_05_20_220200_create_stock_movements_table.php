<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->date('movement_date');
            $table->decimal('qty_change', 14, 4); // signed: + receipt, - issue
            $table->bigInteger('unit_cost_cents');
            $table->bigInteger('total_cost_cents');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_of_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->json('consumed_layers')->nullable(); // FIFO: [{layer_id, qty, unit_cost_cents}, ...]
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'item_id', 'movement_date', 'id']);
            $table->index(['source_type', 'source_id']);
            $table->index(['journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};

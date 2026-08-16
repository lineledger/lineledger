<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The components of a Bundle item: a parent (bundle) item references other
     * items + quantities. Selecting the bundle on a sale expands into one line
     * per component. Scoped via the parent item (no own company_id), like the
     * document line tables.
     */
    public function up(): void
    {
        Schema::create('item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('component_item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 12, 4)->default('1.0000');
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();

            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_components');
    }
};

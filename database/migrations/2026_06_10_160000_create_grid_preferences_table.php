<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grid_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('grid_key', 100);
            $table->json('visible_columns');
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'grid_key'], 'grid_preferences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grid_preferences');
    }
};

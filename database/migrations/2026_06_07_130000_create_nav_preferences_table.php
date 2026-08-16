<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nav_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('item_key', 100);
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'item_key'], 'nav_preferences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_preferences');
    }
};

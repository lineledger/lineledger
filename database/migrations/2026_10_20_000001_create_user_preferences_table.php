<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pref_key', 100);
            $table->json('value');
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'pref_key'], 'user_preferences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};

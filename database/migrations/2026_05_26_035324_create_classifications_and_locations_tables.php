<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['classifications', 'locations'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'name']);
                $table->index(['company_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('classifications');
    }
};

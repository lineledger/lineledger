<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('report_group_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->string('subtype', 40)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_passthrough')->default(false);
            $table->timestamps();

            $table->index(['report_group_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_group_lines');
    }
};

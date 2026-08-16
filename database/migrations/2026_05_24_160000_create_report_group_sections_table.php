<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_group_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_group_id')->constrained()->cascadeOnDelete();
            $table->string('statement', 20);
            $table->string('group_key', 40);
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['report_group_id', 'statement', 'group_key', 'sort_order'], 'rgs_anchor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_group_sections');
    }
};

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
        Schema::create('report_group_account_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_group_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Each source account maps to exactly one line within a group.
            $table->unique(['report_group_id', 'account_id']);
            $table->index('report_group_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_group_account_maps');
    }
};

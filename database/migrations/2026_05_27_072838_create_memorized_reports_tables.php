<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memorized_report_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
        });

        Schema::create('memorized_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('memorized_report_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('report_key', 100);
            $table->string('name');
            $table->json('settings');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memorized_reports');
        Schema::dropIfExists('memorized_report_groups');
    }
};

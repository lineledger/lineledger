<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('period_preset', 40)->default('last_month');
            $table->boolean('show_cover')->default(true);
            $table->boolean('show_logo')->default(true);
            $table->boolean('show_toc')->default(true);
            $table->text('preliminary_text')->nullable();
            $table->text('end_notes')->nullable();
            $table->timestamps();

            $table->index(['company_id']);
        });

        Schema::create('report_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_package_id')->constrained()->cascadeOnDelete();
            $table->string('report_key', 100);
            $table->string('label')->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('memorized_report_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'report_package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_package_items');
        Schema::dropIfExists('report_packages');
    }
};

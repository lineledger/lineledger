<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('type', 20);
            $table->string('subtype', 40);
            $table->string('normal_balance', 10);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->bigInteger('balance_cents')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'subtype']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};

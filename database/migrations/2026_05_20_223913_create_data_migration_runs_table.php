<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_migration_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('in_progress'); // in_progress|completed|abandoned
            $table->date('conversion_date');
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->json('step_results')->nullable();
            $table->boolean('open_invoices_use_original_date')->default(true);
            $table->boolean('open_bills_use_original_date')->default(true);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('company_id');
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_migration_runs');
    }
};

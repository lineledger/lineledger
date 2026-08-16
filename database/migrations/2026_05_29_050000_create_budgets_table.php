<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // The calendar year the fiscal year *starts* in. The 12 budget months
            // are anchored to this year via company.fiscal_year_start_month.
            $table->unsignedSmallInteger('fiscal_year');
            // Optional reporting-dimension scope. A budget tied to a class or
            // location only compares against actuals tagged with that dimension.
            $table->foreignId('class_id')->nullable()->constrained('classifications')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};

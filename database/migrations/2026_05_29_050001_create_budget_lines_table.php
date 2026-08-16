<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            // month_1 is the first month of the fiscal year (not calendar January).
            for ($month = 1; $month <= 12; $month++) {
                $table->bigInteger("month_{$month}_cents")->default(0);
            }

            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();

            $table->unique(['budget_id', 'account_id']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};

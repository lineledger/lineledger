<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_run_line_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_run_line_id')->constrained('pay_run_lines')->cascadeOnDelete();
            $table->string('code', 40);  // regular|overtime|bonus|vacation_pay|custom
            $table->string('name');
            $table->bigInteger('amount_cents')->default(0);
            $table->boolean('is_override')->default(false);
            $table->boolean('is_pensionable')->default(true);
            $table->boolean('is_insurable')->default(true);
            $table->boolean('is_taxable')->default(true);
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('t4_box', 10)->nullable();
            $table->foreignId('class_id')->nullable()->constrained('classifications')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->integer('line_order')->default(0);
            $table->timestamps();

            $table->index('pay_run_line_id', 'prle_line_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_run_line_earnings');
    }
};

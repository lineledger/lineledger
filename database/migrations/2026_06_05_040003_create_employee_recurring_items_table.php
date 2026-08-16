<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_recurring_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_payroll_profile_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // earning|deduction
            $table->string('code', 40);
            $table->string('name');
            $table->string('calc_type', 20)->default('fixed'); // fixed|percent_of_gross
            $table->bigInteger('amount_cents')->nullable();
            $table->integer('percent_bp')->nullable(); // basis points when percent_of_gross
            $table->foreignId('liability_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('t4_box', 10)->nullable();
            $table->boolean('reduces_taxable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('line_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'employee_payroll_profile_id'], 'eri_company_profile_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_recurring_items');
    }
};

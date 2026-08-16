<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_payroll_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();

            // Identity / PII (SIN stored encrypted; last4 kept plain for display).
            $table->text('sin_encrypted')->nullable();
            $table->char('sin_last4', 4)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('hire_date')->nullable();
            $table->date('termination_date')->nullable();

            // Tax jurisdiction (province of employment; never 'QC' in v1).
            $table->char('province_of_employment', 2);

            // Pay basis
            $table->string('pay_basis', 20)->default('salary'); // salary|hourly
            $table->bigInteger('annual_salary_cents')->nullable();
            $table->bigInteger('hourly_rate_cents')->nullable();
            $table->decimal('default_hours_per_period', 8, 2)->nullable();
            $table->foreignId('payroll_schedule_id')->nullable()->constrained('payroll_schedules')->nullOnDelete();

            // TD1 claim amounts (cents) + claim codes
            $table->bigInteger('td1_federal_claim_cents')->default(0);
            $table->string('td1_federal_code', 2)->nullable();
            $table->bigInteger('td1_provincial_claim_cents')->default(0);
            $table->string('td1_provincial_code', 2)->nullable();

            // Statutory exemptions + extra tax
            $table->boolean('cpp_exempt')->default(false);
            $table->boolean('ei_exempt')->default(false);
            $table->bigInteger('additional_tax_per_period_cents')->default(0);

            // Vacation
            $table->string('vacation_policy', 20)->default('accrue'); // accrue|pay_each_cheque
            $table->integer('vacation_rate_bp')->default(400); // 400 = 4%
            $table->bigInteger('vacation_balance_cents')->default(0);

            // GL defaults
            $table->foreignId('wage_expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classifications')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'contact_id']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payroll_profiles');
    }
};

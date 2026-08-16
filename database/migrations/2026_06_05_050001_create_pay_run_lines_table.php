<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_run_id')->constrained('pay_runs')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('employee_payroll_profile_id')->constrained('employee_payroll_profiles')->restrictOnDelete();

            // Snapshots taken at calculation time.
            $table->char('province_of_employment', 2);
            $table->string('pay_basis', 20);
            $table->decimal('hours_worked', 8, 2)->nullable();
            $table->decimal('insurable_hours', 8, 2)->default(0); // ROE block 15A
            $table->bigInteger('hourly_rate_cents')->nullable();
            $table->bigInteger('annual_salary_cents')->nullable();

            // Earnings
            $table->bigInteger('regular_earnings_cents')->default(0);
            $table->bigInteger('gross_cents')->default(0);
            $table->bigInteger('cpp_pensionable_cents')->default(0); // T4 box 26
            $table->bigInteger('ei_insurable_cents')->default(0);    // T4 box 24

            // Statutory: computed + nullable override (effective = override ?? computed).
            foreach ([
                'cpp_employee', 'cpp_employer', 'cpp2_employee', 'cpp2_employer',
                'ei_employee', 'ei_employer', 'federal_tax', 'provincial_tax', 'additional_tax',
            ] as $component) {
                $table->bigInteger($component.'_computed_cents')->default(0);
                $table->bigInteger($component.'_override_cents')->nullable();
            }

            // Vacation
            $table->bigInteger('vacation_accrued_cents')->default(0);
            $table->bigInteger('vacation_paid_cents')->default(0);

            // Totals
            $table->bigInteger('total_deductions_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);

            // YTD snapshots frozen at post.
            $table->bigInteger('ytd_pensionable_cents')->default(0);
            $table->bigInteger('ytd_insurable_cents')->default(0);
            $table->bigInteger('ytd_cpp_employee_cents')->default(0);
            $table->bigInteger('ytd_cpp2_employee_cents')->default(0);
            $table->bigInteger('ytd_ei_employee_cents')->default(0);
            $table->bigInteger('ytd_gross_cents')->default(0);
            $table->bigInteger('ytd_tax_cents')->default(0);

            $table->timestamps();

            $table->unique(['pay_run_id', 'contact_id']);
            $table->index(['company_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_run_lines');
    }
};

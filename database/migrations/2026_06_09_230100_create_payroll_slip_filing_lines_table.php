<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One snapshotted slip per recipient inside a finalized filing: the exact
        // associative array the calculator produced at finalize time. The employee
        // portal serves slips from these rows only — never from live recomputation.
        Schema::create('payroll_slip_filing_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_slip_filing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->json('data'); // the slip's associative array from the calculator
            $table->timestamps();

            $table->unique(['payroll_slip_filing_id', 'contact_id'], 'payroll_slip_filing_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_slip_filing_lines');
    }
};

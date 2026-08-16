<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Employer-funded contributions (health/benefit, RPP match) computed per
        // pay-run line. Employer cost only — posts DR expense / CR liability and
        // never touches the employee's net pay or deduction total.
        Schema::create('pay_run_line_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_run_line_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->bigInteger('amount_cents')->default(0);
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('liability_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('t4_box', 10)->nullable();
            $table->integer('line_order')->default(0);
            $table->timestamps();

            $table->index('pay_run_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_run_line_contributions');
    }
};

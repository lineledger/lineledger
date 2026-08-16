<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Run-time, one-off earnings entered on a pay run (overtime hours, a bonus,
        // commission). Kept as structured INPUT — separate from the derived
        // pay_run_line_earnings snapshots, which CalculatePayRun deletes/rebuilds —
        // so they survive a recalculation, exactly like hours_worked.
        Schema::create('pay_run_line_manual_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_run_line_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('calc_kind', 10)->default('amount'); // amount | hours
            $table->bigInteger('amount_cents')->nullable();
            $table->decimal('hours', 8, 2)->nullable();
            $table->integer('multiplier_bp')->default(10000); // 10000 = 1.0× (overtime uses 15000/20000)
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('t4_box', 10)->nullable();
            $table->integer('line_order')->default(0);
            $table->timestamps();

            $table->index('pay_run_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_run_line_manual_earnings');
    }
};

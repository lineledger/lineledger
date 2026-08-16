<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_run_line_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_run_line_id')->constrained('pay_run_lines')->cascadeOnDelete();
            $table->string('code', 40);  // rrsp|benefits|garnishment|union|charitable|custom
            $table->string('name');
            $table->bigInteger('amount_cents')->default(0);
            $table->boolean('is_override')->default(false);
            $table->boolean('reduces_taxable')->default(false);
            $table->foreignId('liability_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('t4_box', 10)->nullable();
            $table->integer('line_order')->default(0);
            $table->timestamps();

            $table->index('pay_run_line_id', 'prld_line_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_run_line_deductions');
    }
};

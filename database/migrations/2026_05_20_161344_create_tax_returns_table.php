<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_agency_id')->constrained('tax_agencies')->restrictOnDelete();
            $table->string('tax_return_no', 40);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft'); // draft|filed|void
            $table->bigInteger('collected_cents')->default(0);
            $table->bigInteger('paid_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);
            $table->string('filing_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('filed_at')->nullable();
            $table->foreignId('filed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'tax_return_no']);
            $table->index(['company_id', 'tax_agency_id', 'period_end']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_returns');
    }
};

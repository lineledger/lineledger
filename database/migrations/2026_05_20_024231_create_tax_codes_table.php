<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->unsignedInteger('rate_basis_points')->default(0); // 500 = 5%, 1300 = 13%
            $table->foreignId('agency_id')->nullable()->constrained('tax_agencies')->nullOnDelete();
            $table->boolean('is_recoverable')->default(true);
            $table->string('applies_to', 20)->default('both'); // sale_only | purchase_only | both
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_codes');
    }
};

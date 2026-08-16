<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('asset_no', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('asset_category_id')->nullable()->constrained('asset_categories')->nullOnDelete();
            $table->foreignId('asset_account_id')
                ->constrained('accounts', 'id', 'assets_asset_acct_fk')->restrictOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')->nullable()
                ->constrained('accounts', 'id', 'assets_accum_dep_acct_fk')->nullOnDelete();
            $table->foreignId('depreciation_expense_account_id')->nullable()
                ->constrained('accounts', 'id', 'assets_dep_exp_acct_fk')->nullOnDelete();
            $table->string('serial_number')->nullable();
            $table->string('location')->nullable();
            $table->date('acquired_date');
            $table->date('in_service_date')->nullable();
            $table->bigInteger('cost_cents')->default(0);
            $table->bigInteger('salvage_value_cents')->default(0);
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->string('status', 20)->default('in-service'); // in-service|disposed|sold|lost
            $table->date('disposed_at')->nullable();
            $table->text('disposal_notes')->nullable();
            $table->nullableMorphs('source');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'asset_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'asset_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};

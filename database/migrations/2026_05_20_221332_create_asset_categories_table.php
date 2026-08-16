<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('default_asset_account_id')->nullable()
                ->constrained('accounts', 'id', 'asset_cats_def_asset_acct_fk')->nullOnDelete();
            $table->foreignId('default_accumulated_depreciation_account_id')->nullable()
                ->constrained('accounts', 'id', 'asset_cats_def_accum_dep_acct_fk')->nullOnDelete();
            $table->foreignId('default_depreciation_expense_account_id')->nullable()
                ->constrained('accounts', 'id', 'asset_cats_def_dep_exp_acct_fk')->nullOnDelete();
            $table->unsignedSmallInteger('default_useful_life_months')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};

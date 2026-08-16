<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('show_logo')->default(true);
            $table->boolean('show_company_info')->default(true);
            $table->boolean('show_tax_number')->default(true);
            $table->boolean('show_item_column')->default(true);
            $table->boolean('show_qty_column')->default(true);
            $table->boolean('show_tax_column')->default(true);
            $table->text('footer_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_settings');
    }
};

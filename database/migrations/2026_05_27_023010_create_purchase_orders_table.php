<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('po_no', 40);
            $table->date('po_date');
            $table->date('expected_date')->nullable();
            $table->foreignId('terms_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->string('status', 20)->default('open'); // open|cancelled (partial|closed are derived)
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->text('memo')->nullable();
            $table->text('vendor_message')->nullable();
            $table->string('ship_to', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'po_no']);
            $table->index(['company_id', 'contact_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'po_date']);
            $table->index(['company_id', 'expected_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};

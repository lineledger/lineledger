<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_id')->constrained()->cascadeOnDelete();

            // Line is EITHER a customer receipt move (receipt_id set) OR an "other" entry (account_id set).
            $table->foreignId('customer_receipt_id')->nullable()->constrained('customer_receipts')->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->text('description')->nullable();
            $table->bigInteger('amount_cents');
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();

            $table->index('deposit_id');
            $table->index('customer_receipt_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_lines');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_cheque')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::table('bill_payments', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('paid_from_account_id')->constrained('payment_methods')->nullOnDelete();
            $table->dropColumn('payment_method');
        });

        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('deposit_to_account_id')->constrained('payment_methods')->nullOnDelete();
            $table->dropColumn('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn('payment_method_id');
            $table->string('payment_method', 40)->nullable()->after('paid_from_account_id');
        });

        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn('payment_method_id');
            $table->string('payment_method', 40)->nullable()->after('deposit_to_account_id');
        });

        Schema::dropIfExists('payment_methods');
    }
};

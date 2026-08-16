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
        Schema::table('customer_receipts', function (Blueprint $table) {
            // Idempotency key for webhook-driven posting: a Stripe PaymentIntent
            // maps to at most one receipt. Fee captured for the separate fee JE.
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->bigInteger('stripe_fee_cents')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->dropColumn(['stripe_payment_intent_id', 'stripe_fee_cents']);
        });
    }
};

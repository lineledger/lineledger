<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_payment_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->timestamps();

            $table->index('bill_payment_id');
            $table->index('bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payment_applications');
    }
};

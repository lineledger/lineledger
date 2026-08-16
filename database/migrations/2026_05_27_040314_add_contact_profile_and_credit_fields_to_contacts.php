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
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('job_title')->nullable()->after('last_name');
            $table->string('mobile')->nullable()->after('phone');
            $table->foreignId('preferred_payment_method_id')->nullable()->after('default_tax_code_id')->constrained('payment_methods')->nullOnDelete();
            $table->string('preferred_delivery_method')->nullable()->after('preferred_payment_method_id');
            // Credit limit in home-currency cents; null means no limit set.
            $table->bigInteger('credit_limit_cents')->nullable()->after('ap_balance_cents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_payment_method_id');
            $table->dropColumn([
                'job_title',
                'mobile',
                'preferred_delivery_method',
                'credit_limit_cents',
            ]);
        });
    }
};

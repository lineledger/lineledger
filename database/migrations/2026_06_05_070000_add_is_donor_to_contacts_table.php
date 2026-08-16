<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Donor role on contacts (parallel to is_customer / is_vendor / is_employee).
     * Surfaced only for registered charities. donor_type is an optional
     * individual/organization classification used on receipts and the T3010.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_donor')->default(false)->after('is_employee');
            $table->string('donor_type')->nullable()->after('is_donor');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['is_donor', 'donor_type']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a dues invoice back to the member it was billed for. Set once when the
     * invoice is generated (like sales_order_id / recurring_document_id); powers the
     * member's dues history and the membership revenue reports.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->after('recurring_document_id')->constrained('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
        });
    }
};

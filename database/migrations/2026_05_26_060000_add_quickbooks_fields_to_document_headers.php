<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds QuickBooks-style header fields. Each document gets only the subset that
     * applies to it (see the per-table closures); estimates and sales orders already
     * carry customer_message, so it is not re-added there. All columns are nullable so
     * existing rows are unaffected.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('sales_rep_id')->nullable()->after('contact_id')->constrained('contacts')->nullOnDelete();
            $table->string('customer_po', 100)->nullable()->after('sales_rep_id');
            $table->date('ship_date')->nullable()->after('due_date');
            $table->string('ship_via')->nullable()->after('ship_date');
            $table->string('fob')->nullable()->after('ship_via');
            $table->string('tracking_no')->nullable()->after('fob');
            $table->text('customer_message')->nullable()->after('memo');
        });

        Schema::table('estimates', function (Blueprint $table): void {
            $table->foreignId('sales_rep_id')->nullable()->after('contact_id')->constrained('contacts')->nullOnDelete();
            $table->string('customer_po', 100)->nullable()->after('sales_rep_id');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('sales_rep_id')->nullable()->after('contact_id')->constrained('contacts')->nullOnDelete();
            $table->string('customer_po', 100)->nullable()->after('sales_rep_id');
            $table->date('ship_date')->nullable()->after('expected_date');
            $table->string('ship_via')->nullable()->after('ship_date');
            $table->string('fob')->nullable()->after('ship_via');
            $table->string('tracking_no')->nullable()->after('fob');
        });

        Schema::table('credit_memos', function (Blueprint $table): void {
            $table->foreignId('sales_rep_id')->nullable()->after('contact_id')->constrained('contacts')->nullOnDelete();
            $table->text('customer_message')->nullable()->after('memo');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_rep_id');
            $table->dropColumn(['customer_po', 'ship_date', 'ship_via', 'fob', 'tracking_no', 'customer_message']);
        });

        Schema::table('estimates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_rep_id');
            $table->dropColumn('customer_po');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_rep_id');
            $table->dropColumn(['customer_po', 'ship_date', 'ship_via', 'fob', 'tracking_no']);
        });

        Schema::table('credit_memos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_rep_id');
            $table->dropColumn('customer_message');
        });
    }
};

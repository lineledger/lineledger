<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Other name" role on contacts (parallel to is_customer / is_vendor /
     * is_employee / is_donor / is_member): a QuickBooks-style one-time payee
     * for cheques and expenses that is not a vendor, customer or employee.
     * Listed under Settings → Lists → Other names and promotable one-way to a
     * directory role via ConvertOtherName. The index backs that list page and
     * the payee picker's role filter.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_other_name')->default(false)->after('is_member');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->index(['company_id', 'is_other_name'], 'contacts_company_is_other_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_company_is_other_name_index');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('is_other_name');
        });
    }
};

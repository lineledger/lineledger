<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A statement line's suggestion now carries the payee (vendor/customer) and,
     * for an outflow, the open bill it appears to settle — alongside the account
     * it already carried — plus which source made the suggestion (rule, history,
     * open bill, vendor default, AI, or the user). All three stay suggest-only:
     * nothing posts until the line is confirmed (match_status = created).
     */
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->foreignId('suggested_contact_id')->nullable()->after('suggested_account_id')->constrained('contacts')->nullOnDelete();
            $table->foreignId('suggested_bill_id')->nullable()->after('suggested_contact_id')->constrained('bills')->nullOnDelete();
            $table->string('suggestion_source', 20)->nullable()->after('suggested_bill_id');

            $table->index(['company_id', 'suggested_contact_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'suggested_contact_id']);
            $table->dropConstrainedForeignId('suggested_bill_id');
            $table->dropConstrainedForeignId('suggested_contact_id');
            $table->dropColumn('suggestion_source');
        });
    }
};
